$ErrorActionPreference = 'Stop'
$projectDirectory = Split-Path -Parent $PSScriptRoot
Push-Location -LiteralPath $projectDirectory
try {
    & docker compose stop
    if ($LASTEXITCODE -ne 0) {
        throw "Docker завершился с кодом $LASTEXITCODE."
    }
    Write-Host 'RibkaYoga остановлена. База, изображения и зависимости сохранены.'
} finally {
    Pop-Location
}
