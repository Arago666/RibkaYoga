#!/usr/bin/env bash
# Reversible rollback: the newly imported database/media are retained for inspection.
set -euo pipefail
umask 0077
[[ ${EUID} -eq 0 ]] || { printf '%s\n' 'Run as root.' >&2; exit 1; }
exec 9>/run/lock/ribkayoga-deploy.lock
flock -n 9 || { printf '%s\n' 'Another deployment or rollback is running.' >&2; exit 1; }
ribka_backup=$(realpath "${1:?Private release backup path is required}")
ribka_scripts=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ribka_php=(php -d extension=/opt/reibkayoga/php-extensions/usr/lib/php/20240924/pdo_pgsql.so)
ribka_project=$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["project"])' "$ribka_backup/server-context.json")
ribka_new_database=$(cat "$ribka_backup/new-database.txt")
ribka_old_sha=$(cat "$ribka_backup/code-sha.txt")
[[ -f $ribka_backup/backup-ready ]]
[[ $ribka_old_sha =~ ^[a-f0-9]{40}$ ]]
cd "$ribka_project"
[[ -z $(git status --porcelain --untracked-files=all) ]] || { printf '%s\n' 'Checkout changed after deployment; reconcile it before rollback.' >&2; exit 1; }
sha256sum --check "$ribka_backup/backup-sha256.txt"
"${ribka_php[@]}" artisan down --retry=60 || true
systemctl stop reibkayoga-php.service
umask 0022
git checkout --detach "$ribka_old_sha"
umask 0077
"${ribka_php[@]}" "$ribka_scripts/server-ops.php" restore-env "$ribka_project" "$ribka_backup" "$ribka_new_database"
umask 0022
ribka_composer=$(realpath "$(command -v composer)")
COMPOSER_ALLOW_SUPERUSER=1 "${ribka_php[@]}" "$ribka_composer" install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --no-scripts > "$ribka_backup/rollback-composer.log" 2>&1
"${ribka_php[@]}" artisan package:discover > "$ribka_backup/rollback-package-discover.log" 2>&1
tar -xzf "$ribka_backup/build.tar.gz" -C "$ribka_project"
chown -R reibkayoga:reibkayoga storage bootstrap/cache
runuser -u reibkayoga -- "${ribka_php[@]}" artisan config:clear
runuser -u reibkayoga -- "${ribka_php[@]}" artisan config:cache
runuser -u reibkayoga -- "${ribka_php[@]}" artisan route:cache
runuser -u reibkayoga -- "${ribka_php[@]}" artisan view:cache
runuser -u reibkayoga -- "${ribka_php[@]}" artisan up
systemctl start reibkayoga-php.service
systemctl is-active --quiet reibkayoga-php.service
printf '\nPrevious code and database restored. HEAD is detached at %s; the main branch retains the new release.\nNew database and additional immutable files are preserved.\n' "$ribka_old_sha"
