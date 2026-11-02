"""Export only the new catalog for future workspace provisioning, no accounts.

Usage: python3 starter.py PATH_TO_SQLITE
The database is read-only. Original rasters are copied into this private pack.
"""
import gzip
import json
import shutil
import sqlite3
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
PROJECT = ROOT.parents[2]


def build(database):
    db = sqlite3.connect(str(Path(database).resolve()))
    db.execute('pragma query_only=on')
    db.row_factory = sqlite3.Row
    tables = {}
    for table in ['catalog_asanas', 'catalog_asana_aliases', 'catalog_categories',
                  'catalog_asana_category', 'catalog_asana_relations', 'media_assets']:
        tables[table] = [dict(row) for row in db.execute(f'select * from {table}').fetchall()]
    manifest = json.loads((ROOT / 'manifest.json').read_text())
    assert len(tables['catalog_asanas']) == len(manifest['items'])
    assert {x['import_key'] for x in tables['catalog_asanas']} == {x['source_key'] for x in manifest['items']}
    assert all((x['import_key'] or '').startswith('spinal-five-v1:') for x in tables['catalog_asanas'])
    referenced = {x['current_image_asset_id'] for x in tables['catalog_asanas']}
    tables['media_assets'] = [x for x in tables['media_assets'] if x['id'] in referenced]
    target = ROOT / 'starter'
    target.mkdir(exist_ok=True)
    for asset in tables['media_assets']:
        assert asset['disk'] == 'local'
        original = Path(asset['original_path'])
        assert not original.is_absolute() and '..' not in original.parts
        source = PROJECT / 'storage/app/private' / original
        destination = target / 'files/private' / original
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, destination)
    keep = {x['original_path'] for x in tables['media_assets']}
    for old in (target / 'files/private').rglob('*'):
        if old.is_file() and old.relative_to(target / 'files/private').as_posix() not in keep:
            old.unlink()
    encoded = json.dumps({'tables': tables}, ensure_ascii=False, sort_keys=True, separators=(',', ':')).encode()
    (target / 'dataset.json.gz').write_bytes(gzip.compress(encoded, mtime=0))
    print(f'Starter: {len(tables["catalog_asanas"])} cards, {len(tables["media_assets"])} original images; no account or practice data')


if __name__ == '__main__':
    build(sys.argv[1])
