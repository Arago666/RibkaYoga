#!/usr/bin/env bash
# Run the scripts extracted from the target Git commit, before changing the live checkout.
set -euo pipefail
umask 0077
[[ ${EUID} -eq 0 ]] || { printf '%s\n' 'Run this operator script as root.' >&2; exit 1; }
command -v flock >/dev/null || { printf '%s\n' 'Required command missing: flock' >&2; exit 1; }
exec 9>/run/lock/ribkayoga-deploy.lock
flock -n 9 || { printf '%s\n' 'Another deployment or rollback is running.' >&2; exit 1; }
ribka_archive=$(realpath "${1:?Private content.tar.gz is required}")
ribka_target=${2:?Full target Git commit SHA is required}
ribka_project=${3:-/var/www/reibkayoga}
ribka_scripts=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ribka_extension=/opt/reibkayoga/php-extensions/usr/lib/php/20240924/pdo_pgsql.so
ribka_php=(php -d "extension=$ribka_extension")
ribka_stamp=$(date -u +%Y%m%dT%H%M%SZ)
ribka_backup=/root/ribkayoga-backups/release-$ribka_stamp
ribka_new_database=ribka_release_$(date -u +%Y%m%d_%H%M%S)
ribka_stopped=0
[[ $ribka_target =~ ^[a-f0-9]{40}$ ]] || { printf '%s\n' 'Expected a full 40-character commit SHA.' >&2; exit 1; }
for ribka_command in git php composer npm python3 pg_dump pg_restore psql createdb runuser systemctl tar sha256sum curl cmp install realpath flock; do
  command -v "$ribka_command" >/dev/null || { printf 'Required command missing: %s\n' "$ribka_command" >&2; exit 1; }
done
ribka_composer=$(realpath "$(command -v composer)")
[[ -f $ribka_composer ]]
[[ -s $ribka_archive && -f $ribka_extension ]]
[[ -f $ribka_scripts/server-ops.php && -f $ribka_scripts/import.php && -f $ribka_scripts/rollback.sh ]]
cd "$ribka_project"
[[ -z $(git status --porcelain --untracked-files=all) ]] || { printf '%s\n' 'Server checkout has local changes. Save and reconcile them before deployment.' >&2; exit 1; }
[[ $(git branch --show-current) == main ]] || { printf '%s\n' 'Expected the main branch.' >&2; exit 1; }
git fetch origin main
[[ $(git rev-parse origin/main) == "$ribka_target" ]]
ribka_old_sha=$(git rev-parse HEAD)
git merge-base --is-ancestor "$ribka_old_sha" "$ribka_target"
for ribka_file in server.sh server-ops.php import.php rollback.sh; do
  git show "$ribka_target:scripts/deploy/$ribka_file" | cmp - "$ribka_scripts/$ribka_file"
done
install -d -m 700 "$ribka_backup" "$ribka_backup/package" "$ribka_backup/operator"
cp "$ribka_scripts/"{server.sh,server-ops.php,import.php,rollback.sh} "$ribka_backup/operator/"
printf '%s\n' "$ribka_old_sha" > "$ribka_backup/code-sha.txt"
printf '%s\n' "$ribka_target" > "$ribka_backup/target-sha.txt"
printf '%s\n' "$ribka_new_database" > "$ribka_backup/new-database.txt"
python3 - "$ribka_archive" "$ribka_backup/package" <<'PY'
import pathlib, sys, tarfile
archive, destination = sys.argv[1:]
with tarfile.open(archive, 'r:gz') as source:
    members = source.getmembers()
    if len(members) > 20000 or sum(m.size for m in members) > 2 * 1024**3:
        raise SystemExit('Content archive exceeds the operator limits.')
    for member in members:
        path = pathlib.PurePosixPath(member.name)
        if path.is_absolute() or '..' in path.parts or not path.parts or path.parts[0] != 'content' or not (member.isfile() or member.isdir()):
            raise SystemExit('Unsafe archive entry.')
    # Every path and entry type was checked above, including all symlinks.
    source.extractall(destination, members=members)
PY
ribka_package=$ribka_backup/package/content
"${ribka_php[@]}" "$ribka_scripts/import.php" --package="$ribka_package" --package-only > "$ribka_backup/package-preflight.json"
"${ribka_php[@]}" "$ribka_scripts/server-ops.php" preflight "$ribka_project" "$ribka_backup" "$ribka_new_database"
git bundle create "$ribka_backup/repository.bundle" --all > "$ribka_backup/git-bundle.log" 2>&1
git bundle verify "$ribka_backup/repository.bundle" >> "$ribka_backup/git-bundle.log" 2>&1
git archive --format=tar.gz --output="$ribka_backup/code.tar.gz" "$ribka_old_sha"
failure() {
  ribka_status=$?
  if [[ $ribka_stopped -eq 1 ]]; then
    if [[ -f $ribka_backup/backup-ready ]]; then
      runuser -u reibkayoga -- "${ribka_php[@]}" artisan down --retry=60 > "$ribka_backup/failure-maintenance.log" 2>&1 || true
      systemctl stop reibkayoga-php.service || true
      printf '\nRelease stopped; the site is in maintenance. Old database and files are preserved.\nRollback command:\n  bash %q %q\n' "$ribka_backup/operator/rollback.sh" "$ribka_backup" >&2
    else
      # No code or database switch happens until the complete backup is verified.
      runuser -u reibkayoga -- "${ribka_php[@]}" artisan up || true
      systemctl start reibkayoga-php.service || true
      printf '\nBackup could not be completed. Original code/database remain active; inspect %s\n' "$ribka_backup" >&2
    fi
  else
    printf '\nRelease stopped before maintenance. Inspect private logs: %s\n' "$ribka_backup" >&2
  fi
  exit "$ribka_status"
}
trap failure ERR
runuser -u reibkayoga -- "${ribka_php[@]}" artisan down --retry=60
ribka_stopped=1
systemctl stop reibkayoga-php.service
"${ribka_php[@]}" "$ribka_scripts/server-ops.php" backup "$ribka_project" "$ribka_backup" "$ribka_new_database"
pg_restore --list "$ribka_backup/database.dump" > "$ribka_backup/database-contents.txt"
tar -czf "$ribka_backup/user-files.tar.gz" storage/app
tar -czf "$ribka_backup/build.tar.gz" public/build
sha256sum "$ribka_backup/"{database.dump,environment.env,user-files.tar.gz,build.tar.gz,code.tar.gz,repository.bundle} > "$ribka_backup/backup-sha256.txt"
printf '%s\n' complete > "$ribka_backup/backup-ready"
umask 0022
git merge --ff-only "$ribka_target"
[[ $(git rev-parse HEAD) == "$ribka_target" ]]
COMPOSER_ALLOW_SUPERUSER=1 "${ribka_php[@]}" "$ribka_composer" install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --no-scripts > "$ribka_backup/composer.log" 2>&1
"${ribka_php[@]}" artisan package:discover > "$ribka_backup/package-discover.log" 2>&1
npm ci --no-audit --no-fund > "$ribka_backup/npm.log" 2>&1
npm run build > "$ribka_backup/build.log" 2>&1
umask 0077
"${ribka_php[@]}" "$ribka_scripts/server-ops.php" create-database "$ribka_project" "$ribka_backup" "$ribka_new_database"
"${ribka_php[@]}" "$ribka_scripts/import.php" --project="$ribka_project" --package="$ribka_package" --database="$ribka_new_database" --migrate --force --install-media --report="$ribka_backup/import-report.json" > "$ribka_backup/import.log" 2>&1
"${ribka_php[@]}" "$ribka_scripts/import.php" --project="$ribka_project" --package="$ribka_package" --database="$ribka_new_database" --verify-only --report="$ribka_backup/verification.json" > "$ribka_backup/verify.log" 2>&1
"${ribka_php[@]}" "$ribka_scripts/server-ops.php" switch-database "$ribka_project" "$ribka_backup" "$ribka_new_database"
chown -R reibkayoga:reibkayoga storage bootstrap/cache
find public/build -type d -exec chmod 755 {} +
find public/build -type f -exec chmod 644 {} +
runuser -u reibkayoga -- "${ribka_php[@]}" artisan config:clear > "$ribka_backup/cache.log"
runuser -u reibkayoga -- "${ribka_php[@]}" artisan config:cache >> "$ribka_backup/cache.log"
runuser -u reibkayoga -- "${ribka_php[@]}" artisan route:cache >> "$ribka_backup/cache.log"
runuser -u reibkayoga -- "${ribka_php[@]}" artisan view:cache >> "$ribka_backup/cache.log"
runuser -u reibkayoga -- "${ribka_php[@]}" artisan up
ribka_wait_for_site() {
  local ribka_deadline=$((SECONDS + 30)) ribka_ready ribka_path ribka_remaining ribka_timeout ribka_code
  while ((SECONDS < ribka_deadline)); do
    ribka_ready=0
    if systemctl is-active --quiet reibkayoga-php.service; then
      ribka_ready=1
      for ribka_path in up login; do
        ribka_remaining=$((ribka_deadline - SECONDS))
        if ((ribka_remaining <= 0)); then
          ribka_ready=0
          break
        fi
        ribka_timeout=$((ribka_remaining < 2 ? ribka_remaining : 2))
        if ! ribka_code=$(curl --silent --show-error --connect-timeout 1 --max-time "$ribka_timeout" --output /dev/null --write-out '%{http_code}' "$ribka_url/$ribka_path" 2>> "$ribka_backup/readiness.log"); then
          ribka_code=000
        fi
        printf '%s HTTP %s\n' "$ribka_path" "$ribka_code" >> "$ribka_backup/readiness.log"
        if [[ $ribka_code != 200 ]]; then
          ribka_ready=0
          break
        fi
      done
      if [[ $ribka_ready -eq 1 ]] && systemctl is-active --quiet reibkayoga-php.service; then
        return 0
      fi
    fi
    if ((SECONDS < ribka_deadline)); then
      sleep 1
    fi
  done
  printf 'PHP service did not become HTTP-ready within 30 seconds. Inspect %s/readiness.log\n' "$ribka_backup" >&2
  return 1
}
ribka_url=$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["app_url"].rstrip("/"))' "$ribka_backup/server-context.json")
systemctl start reibkayoga-php.service
ribka_wait_for_site
trap - ERR
printf '\nPublished commit %s\nAddress: %s\nPrivate backup and rollback: %s\nPrevious database is preserved.\n' "$ribka_target" "$ribka_url" "$ribka_backup"
