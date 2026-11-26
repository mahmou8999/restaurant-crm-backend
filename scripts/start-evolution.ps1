# start-evolution.ps1 - Run Evolution API from source (Node.js), no Docker.
#
# Usage: from PowerShell inside the backend folder:
#   .\scripts\start-evolution.ps1
#
# The script reads EVOLUTION_API_KEY from Laravel's .env and uses it
# as Evolution's AUTHENTICATION_API_KEY, so both keys stay identical.
# Keep this window open while you want WhatsApp connected.
# (English-only on purpose: Windows PowerShell 5.1 misreads UTF-8
#  without BOM, which breaks scripts containing Arabic text.)

$ErrorActionPreference = 'Stop'

$BackendDir = Split-Path -Parent $PSScriptRoot
$EnvFile = Join-Path $BackendDir '.env'

if (-not (Test-Path $EnvFile)) {
    throw ".env not found in $BackendDir - copy .env.example to .env first and set EVOLUTION_API_KEY."
}

$apiKey = $null
foreach ($line in Get-Content $EnvFile) {
    if ($line -match '^\s*EVOLUTION_API_KEY\s*=\s*(.+?)\s*$') {
        $apiKey = $Matches[1].Trim().Trim('"').Trim("'")
        break
    }
}
if ([string]::IsNullOrWhiteSpace($apiKey)) {
    throw "EVOLUTION_API_KEY is empty in .env - set a key there first."
}

# Node.js 20+
try {
    $nodeRaw = (node --version).Trim()
} catch {
    throw "Node.js is not installed - download it from https://nodejs.org/ (version 20 or higher)."
}
$nodeMajor = [int]($nodeRaw.TrimStart('v').Split('.')[0])
if ($nodeMajor -lt 20) {
    throw "Node.js is $nodeRaw - version 20 or higher is required."
}

try {
    $null = git --version
} catch {
    throw "Git is not installed - download it from https://git-scm.com/."
}

$EvolutionDir = Join-Path (Split-Path -Parent $BackendDir) 'evolution-api'

if (-not (Test-Path $EvolutionDir)) {
    Write-Host "Cloning Evolution from GitHub..."
    git clone https://github.com/evolution-foundation/evolution-api.git $EvolutionDir
}

Set-Location $EvolutionDir

if (-not (Test-Path 'node_modules')) {
    Write-Host "Installing Evolution packages (npm install)..."
    npm install
    if ($LASTEXITCODE -ne 0) { throw "npm install failed. See the errors above." }
}

# Configure Evolution's .env with Laravel's key
$EvolutionEnv = Join-Path $EvolutionDir '.env'
# Evolution connects to the database at startup even with
# DATABASE_ENABLED=false (a placeholder URI crashes with P1001), so it
# uses the same MySQL Laravel already runs on (127.0.0.1:3307, root,
# no password) with a real 'evolution' database.
$wanted = [ordered]@{
    'SERVER_TYPE'            = 'http'
    'SERVER_PORT'            = '8080'
    'AUTHENTICATION_API_KEY' = $apiKey
    'DATABASE_PROVIDER'      = 'mysql'
    'DATABASE_CONNECTION_URI' = 'mysql://root:@127.0.0.1:3307/evolution'
    'DATABASE_ENABLED'       = 'true'
}
$lines = @()
if (Test-Path $EvolutionEnv) {
    $lines = @(Get-Content $EvolutionEnv)
}
foreach ($key in $wanted.Keys) {
    $found = $false
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match "^\s*$key\s*=") {
            $lines[$i] = "$key=$($wanted[$key])"
            $found = $true
        }
    }
    if (-not $found) { $lines += "$key=$($wanted[$key])" }
}
$lines | Set-Content $EvolutionEnv -Encoding utf8

# Rebuild when dist/main.js is missing or when the database provider
# changed (the Prisma client and migrations are provider-specific).
$providerMarker = Join-Path $EvolutionDir '.evolution-build-provider'
$builtProvider = ''
if (Test-Path $providerMarker) { $builtProvider = (Get-Content $providerMarker -Raw).Trim() }
if ((-not (Test-Path 'dist\main.js')) -or ($builtProvider -ne 'mysql')) {
    Write-Host "Creating the evolution database in MySQL (if missing)..."
    php (Join-Path $BackendDir 'artisan') tinker --execute "DB::statement('CREATE DATABASE IF NOT EXISTS evolution');"
    if ($LASTEXITCODE -ne 0) { throw "Could not create the evolution database. Is MySQL running on 127.0.0.1:3307 (the same MySQL Laravel uses)?" }

    Write-Host "Generating Prisma client (npm run db:generate)..."
    npm run db:generate
    if ($LASTEXITCODE -ne 0) { throw "Prisma client generation failed (npm run db:generate). See the errors above." }

    Write-Host "Creating Evolution tables (prisma migrate deploy)..."
    New-Item -ItemType Directory -Force -Path (Join-Path $EvolutionDir 'prisma\migrations') | Out-Null
    Copy-Item (Join-Path $EvolutionDir 'prisma\mysql-migrations\*') (Join-Path $EvolutionDir 'prisma\migrations') -Recurse -Force
    npx prisma migrate deploy --schema prisma\mysql-schema.prisma
    if ($LASTEXITCODE -ne 0) { throw "Evolution database migration failed (prisma migrate deploy). See the errors above." }

    Write-Host "Building Evolution (npm run build)..."
    npm run build
    if ($LASTEXITCODE -ne 0) { throw "Evolution build failed (npm run build). See the errors above." }

    'mysql' | Set-Content $providerMarker -Encoding ascii
}

Write-Host ""
Write-Host "Evolution will run on http://127.0.0.1:8080 - keep this window open."
Write-Host "Then request the QR from the WhatsAppConnect page in the frontend (test number first)."
Write-Host ""
npm run start:prod
