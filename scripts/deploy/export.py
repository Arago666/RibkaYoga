#!/usr/bin/env python3
"""Operator export of one SQLite snapshot and its immutable private files.

The output contains account data. Keep the directory/archive private and out of Git.
This command does not change the source database or copy .env/application keys.
"""

import argparse
from contextlib import closing
import gzip
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import shutil
import sqlite3
import tarfile
from datetime import datetime, timezone
from urllib.parse import quote


EXCLUDED = {
    "migrations", "cache", "cache_locks", "sessions", "password_reset_tokens",
    "jobs", "job_batches", "failed_jobs",
}


def digest(path):
    value = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            value.update(block)
    return value.hexdigest()


def encoded(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"), allow_nan=False).encode("utf-8")


def rows_digest(rows):
    # Multiset: independent of storage/row order, includes duplicate rows.
    return hashlib.sha256(b"\n".join(sorted(encoded(row) for row in rows))).hexdigest()


def identifier(value):
    if not value or not value.replace("_", "a").isalnum() or not value[0].isalpha():
        raise ValueError("Unexpected schema identifier")
    return '"' + value + '"'


def private_path(root, relative):
    if not isinstance(relative, str) or not relative or "\\" in relative or "\x00" in relative:
        raise ValueError("Invalid private asset path")
    parts = PurePosixPath(relative)
    if parts.is_absolute() or any(part in {"", ".", ".."} for part in relative.split("/")):
        raise ValueError("Unsafe private asset path")
    current = root
    for part in parts.parts:
        current = current / part
        if current.is_symlink():
            raise ValueError("Symlink in private asset path")
    if not current.is_file() or not current.resolve().is_relative_to(root):
        raise ValueError("Missing or unsafe private asset file")
    return current


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--database", required=True, type=Path)
    parser.add_argument("--private-storage", "--storage", dest="private_storage", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path, help="New private directory, e.g. .runtime/release-20261102/content")
    args = parser.parse_args()
    os.umask(0o077)
    source = args.database.resolve(strict=True)
    storage = args.private_storage.resolve(strict=True)
    output = args.output.absolute()
    if output.exists() or output.with_suffix(".tar.gz").exists():
        raise ValueError("Output already exists; select a new directory to preserve the previous backup")
    output.mkdir(parents=True, mode=0o700)
    snapshot = output / "snapshot.sqlite"
    with closing(sqlite3.connect("file:" + quote(str(source), safe="/") + "?mode=ro", uri=True, timeout=30)) as live:
        live.execute("PRAGMA temp_store=MEMORY")
        with closing(sqlite3.connect(snapshot)) as copied:
            copied.execute("PRAGMA temp_store=MEMORY")
            live.backup(copied)
            # A standalone backup must not depend on WAL/SHM sidecars.
            copied.execute("PRAGMA journal_mode=DELETE")
    snapshot.chmod(0o600)
    with closing(sqlite3.connect("file:" + quote(str(snapshot), safe="/") + "?mode=ro&immutable=1", uri=True)) as connection:
        connection.execute("PRAGMA temp_store=MEMORY")
        connection.row_factory = sqlite3.Row
        if connection.execute("PRAGMA integrity_check").fetchone()[0] != "ok":
            raise ValueError("Snapshot integrity check failed")
        if connection.execute("PRAGMA foreign_key_check").fetchall():
            raise ValueError("Snapshot foreign keys are invalid")
        names = sorted(row[0] for row in connection.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"))
        tables = {}
        for name in names:
            if name in EXCLUDED:
                continue
            columns = [dict(row) for row in connection.execute("PRAGMA table_info(" + identifier(name) + ")")]
            rows = [dict(row) for row in connection.execute("SELECT * FROM " + identifier(name))]
            if any(isinstance(value, bytes) for row in rows for value in row.values()):
                raise ValueError("Binary database columns require an explicit portable encoding")
            tables[name] = {"columns": columns, "rows": rows}
        required = {"users", "workspaces", "media_assets", "catalog_asanas", "practice_documents", "practice_cells"}
        if not required.issubset(tables):
            raise ValueError("Required application tables are missing")
        # The server key is intentionally retained. Do not export key-bound 2FA data.
        if any(row.get("two_factor_secret") or row.get("two_factor_recovery_codes") for row in tables["users"]["rows"]):
            raise ValueError("Nonempty 2FA secrets require an explicit key migration plan")
        migrations = [row[0] for row in connection.execute("SELECT migration FROM migrations ORDER BY migration")]

    assets = set()
    for row in tables["media_assets"]["rows"]:
        if row["disk"] != "local":
            raise ValueError("Export only supports local private media")
        assets.update(path for path in (row["original_path"], row["thumbnail_path"]) if path)
    for row in tables.get("media_audio_assets", {}).get("rows", []):
        if row["disk"] != "local":
            raise ValueError("Export only supports local private audio")
        if row.get("path"):
            assets.add(row["path"])
    assets.update(row["avatar_path"] for row in tables["users"]["rows"] if row.get("avatar_path"))
    files = []
    for relative in sorted(assets):
        original = private_path(storage, relative)
        destination = output / "files" / relative
        destination.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
        shutil.copyfile(original, destination)
        destination.chmod(0o600)
        copied_hash = digest(destination)
        if copied_hash != digest(original):
            raise ValueError("Private asset changed during export")
        files.append({"path": relative, "bytes": destination.stat().st_size, "sha256": copied_hash})
    created = datetime.now(timezone.utc).isoformat(timespec="seconds")
    dataset = {"format": "ribka-content-export-v1", "created_at": created, "tables": tables}
    with (output / "dataset.json.gz").open("wb") as raw:
        with gzip.GzipFile(filename="", mode="wb", fileobj=raw, mtime=0) as compressed:
            compressed.write(encoded(dataset))
    manifest = {
        "format": "ribka-content-manifest-v1", "created_at": created,
        "dataset_sha256": digest(output / "dataset.json.gz"), "sqlite_sha256": digest(snapshot),
        "excluded_tables": sorted(EXCLUDED), "migrations": migrations,
        "tables": {name: {"count": len(table["rows"]), "raw_sha256": rows_digest(table["rows"])} for name, table in tables.items()},
        "files": files,
    }
    (output / "manifest.json").write_bytes(encoded(manifest) + b"\n")
    archive = output.with_suffix(".tar.gz")
    with tarfile.open(archive, "w:gz", format=tarfile.PAX_FORMAT) as bundle:
        bundle.add(output, arcname=output.name, recursive=True)
    archive.chmod(0o600)
    receipt = {
        "archive": str(archive), "archive_sha256": digest(archive), "created_at": created,
        "tables": {name: item["count"] for name, item in manifest["tables"].items()},
        "files": len(files), "file_bytes": sum(item["bytes"] for item in files),
        "snapshot_integrity": "ok", "foreign_key_violations": 0,
    }
    (output.parent / (output.name + "-receipt.json")).write_bytes(encoded(receipt) + b"\n")
    print(json.dumps(receipt, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
