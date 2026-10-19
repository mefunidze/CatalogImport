$ErrorActionPreference = 'Stop'
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    if (-not (Test-Path .env)) { Copy-Item .env.example .env }
    docker compose build app
    if ($LASTEXITCODE -ne 0) { throw 'Build failed.' }
    docker compose run --rm --no-deps app composer install --no-interaction --prefer-dist --no-progress
    if ($LASTEXITCODE -ne 0) { throw 'Dependencies failed.' }
    if (-not (Select-String -Path .env -Pattern '^APP_KEY=.+$' -Quiet)) {
        docker compose run --rm --no-deps app php artisan key:generate --force
        if ($LASTEXITCODE -ne 0) { throw 'Key generation failed.' }
    }
    if ($LASTEXITCODE -ne 0) { throw 'Setup failed.' }
    docker compose up -d app worker
    if ($LASTEXITCODE -ne 0) { throw 'Startup failed.' }
    Write-Host 'http://localhost:8090'
} finally {
    Pop-Location
}
