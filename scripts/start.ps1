$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Invoke-RibkaDocker {
    # Keep this a simple function: advanced parameters consume Docker's -d flag.
    & docker @args
    if ($LASTEXITCODE -ne 0) {
        throw "Docker завершился с кодом ${LASTEXITCODE}: docker $($args -join ' ')"
    }
}

$projectDirectory = Split-Path -Parent $PSScriptRoot
Push-Location -LiteralPath $projectDirectory
try {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw 'Установите Docker Desktop и включите Linux containers.'
    }
    Invoke-RibkaDocker version --format '{{.Server.Version}}'
    Invoke-RibkaDocker compose version
    & (Join-Path $PSScriptRoot 'initialize-env.ps1')

    $environmentText = Get-Content -Raw -LiteralPath '.env'
    foreach ($requiredSetting in @('APP_KEY', 'DB_PASSWORD')) {
        if ($environmentText -notmatch "(?m)^${requiredSetting}=\S+") {
            throw "Заполните $requiredSetting в существующем .env. Скрипт не меняет ваши настройки."
        }
    }
    $applicationPort = 8080
    if ($environmentText -match '(?m)^APP_PORT=(\d+)') {
        $applicationPort = [int]$Matches[1]
    }

    Write-Host 'Собираем PHP-контейнер и запускаем PostgreSQL…'
    Invoke-RibkaDocker compose build app
    Invoke-RibkaDocker compose up -d --wait db

    Write-Host 'Устанавливаем зависимости и применяем новые миграции…'
    Invoke-RibkaDocker compose run --rm app composer install --no-interaction --prefer-dist
    Invoke-RibkaDocker compose run --rm app php artisan migrate --force

    Write-Host 'Собираем интерфейс из package-lock.json…'
    Invoke-RibkaDocker compose --profile tools run --rm node npm ci
    Invoke-RibkaDocker compose --profile tools run --rm node npm run build
    Invoke-RibkaDocker compose up -d --wait app web
    # Nginx resolves the PHP upstream at startup; app may have a new container IP.
    Invoke-RibkaDocker compose restart web

    $applicationUrl = "http://localhost:$applicationPort"
    $ready = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        try {
            $healthResponse = Invoke-WebRequest -Uri "$applicationUrl/up" -UseBasicParsing -TimeoutSec 3
            $loginResponse = Invoke-WebRequest -Uri "$applicationUrl/login" -UseBasicParsing -TimeoutSec 3
            if ($healthResponse.StatusCode -eq 200 -and $loginResponse.StatusCode -eq 200) {
                $ready = $true
                break
            }
        } catch {
            if ($attempt -eq 29) {
                Write-Warning 'HTTP-проверка не прошла. Посмотрите docker compose logs --tail=80 app web.'
            }
        }
        Start-Sleep -Seconds 1
    }
    if (-not $ready) {
        throw 'Контейнеры запущены, но страница входа ещё не отвечает. Данные и тома сохранены.'
    }
    Write-Host "RibkaYoga доступна: $applicationUrl"
    Write-Host 'Аккаунты не создаются автоматически. Инструкции для первого владельца и преподавателя — в README.md.'
} finally {
    Pop-Location
}
