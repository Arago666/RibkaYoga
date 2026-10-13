param([ValidateRange(1, 65535)][int]$Port = 8080)

$ErrorActionPreference = 'Stop'
$ribkaProjectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
if (-not (Test-Path -LiteralPath (Join-Path $ribkaProjectRoot 'vendor/autoload.php'))) {
    throw 'Run composer install in the project directory first.'
}
if (-not (Test-Path -LiteralPath (Join-Path $ribkaProjectRoot '.env'))) {
    throw 'Prepare the local .env and database first.'
}
if (-not (Test-Path -LiteralPath (Join-Path $ribkaProjectRoot 'public/build/manifest.json'))) {
    throw 'Run npm ci and npm run build in the project directory first.'
}
Write-Host "RibkaYoga: http://127.0.0.1:$Port (Ctrl+C to stop)"
Push-Location -LiteralPath (Join-Path $ribkaProjectRoot 'public')
try {
    & php -d upload_max_filesize=10M -d post_max_size=48M -d memory_limit=512M -S "127.0.0.1:$Port" '../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'
    if ($LASTEXITCODE -ne 0) { throw "PHP exited with code $LASTEXITCODE." }
} finally {
    Pop-Location
}
