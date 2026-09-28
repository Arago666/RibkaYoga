#!/usr/bin/env sh
set -eu

project_directory=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_directory"

command -v docker >/dev/null 2>&1 || { printf '%s\n' 'Установите Docker и плагин Docker Compose.' >&2; exit 1; }
docker version --format '{{.Server.Version}}'
docker compose version

if [ ! -f .env ]; then
    command -v openssl >/dev/null 2>&1 || { printf '%s\n' 'Для первого запуска необходим openssl.' >&2; exit 1; }
    umask 077
    environment_temp=$(mktemp "$project_directory/.env.ribka.XXXXXX")
    trap 'rm -f "$environment_temp"' EXIT HUP INT TERM
    application_key="base64:$(openssl rand -base64 32)"
    database_password=$(openssl rand -hex 32)
    sed -e "s|^APP_KEY=.*|APP_KEY=$application_key|" -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$database_password|" .env.example > "$environment_temp"
    # noclobber also protects an .env created concurrently by another operator.
    (set -C; cat "$environment_temp" > .env) || { printf '%s\n' '.env уже появился. Перезапустите скрипт; настройки сохранены.' >&2; exit 1; }
    rm -f "$environment_temp"
    trap - EXIT HUP INT TERM
    unset application_key database_password
    printf '%s\n' 'Создан .env со случайными ключами.'
fi

grep -Eq '^APP_KEY=[^[:space:]]+' .env || { printf '%s\n' 'Заполните APP_KEY в существующем .env.' >&2; exit 1; }
grep -Eq '^DB_PASSWORD=[^[:space:]]+' .env || { printf '%s\n' 'Заполните DB_PASSWORD в существующем .env.' >&2; exit 1; }
application_port=$(sed -n 's/^APP_PORT=\([0-9][0-9]*\).*/\1/p' .env | head -n 1)
application_port=${application_port:-8080}

docker compose build app
docker compose up -d --wait db
docker compose run --rm app composer install --no-interaction --prefer-dist
docker compose run --rm app php artisan migrate --force
docker compose --profile tools run --rm node npm ci
docker compose --profile tools run --rm node npm run build
docker compose up -d --wait app web
# Refresh the PHP upstream after a rebuilt app container changes its address.
docker compose restart web

# Use PHP inside the existing container: curl is not a host prerequisite.
attempt=0
while [ "$attempt" -lt 30 ]; do
    if docker compose exec -T app php -r '$context = stream_context_create(["http" => ["timeout" => 3, "ignore_errors" => true]]); foreach (["up", "login"] as $path) { $body = @file_get_contents("http://web/".$path, false, $context); if ($body === false || !isset($http_response_header[0]) || strpos($http_response_header[0], " 200 ") === false) { exit(1); } }' 2>/dev/null; then
        printf '\n%s\n' "RibkaYoga доступна: http://localhost:$application_port"
        printf '%s\n' 'Аккаунты не создаются автоматически. Инструкции — в README.md.'
        exit 0
    fi
    attempt=$((attempt + 1))
    sleep 1
done

printf '%s\n' 'Страница входа не отвечает. Данные сохранены. Проверьте: docker compose logs --tail=80 app web' >&2
exit 1
