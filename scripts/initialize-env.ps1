$ErrorActionPreference = 'Stop'
$projectDirectory = Split-Path -Parent $PSScriptRoot
$environmentPath = Join-Path $projectDirectory '.env'
if (-not (Test-Path -LiteralPath $environmentPath)) {
    $environmentText = Get-Content -Raw -LiteralPath (Join-Path $projectDirectory '.env.example')
    $randomBytes = New-Object byte[] 32
    $randomGenerator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    $randomGenerator.GetBytes($randomBytes)
    $appKey = 'base64:' + [Convert]::ToBase64String($randomBytes)
    $randomGenerator.GetBytes($randomBytes)
    $databasePassword = [BitConverter]::ToString($randomBytes).Replace('-', '').ToLowerInvariant()
    $randomGenerator.Dispose()
    $environmentText = $environmentText.Replace('APP_KEY=', ('APP_KEY=' + $appKey)).Replace('DB_PASSWORD=', ('DB_PASSWORD=' + $databasePassword))
    [IO.File]::WriteAllText($environmentPath, $environmentText, [Text.UTF8Encoding]::new($false))
    Write-Output 'Создан .env со случайными ключами. Существующие настройки не изменены.'
}
