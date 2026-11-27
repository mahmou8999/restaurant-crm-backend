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

# Patch the WhatsApp version fetch: upstream src/utils/fetchLatestWaWebVersion.ts
# fetches https://web.whatsapp.com/sw.js with NO timeout, and both GET / and the
# Baileys connection await it. On networks with broken TLS (mobile hotspot)
# that request hangs forever, so the server accepts connections but never
# answers. Add a 5s timeout and fall back to Baileys' pinned version
# ([2, 3000, 1043857760] from WhiskeySockets/Baileys master) instead of
# fetching it from GitHub, which is unreachable on the same network.
$patched = $false
$waVersionFile = Join-Path $EvolutionDir 'src\utils\fetchLatestWaWebVersion.ts'
$waVersionText = Get-Content $waVersionFile -Raw
if (-not $waVersionText.Contains('timeout: 5000')) {
    if (-not $waVersionText.Contains("responseType: 'json',")) { throw "Cannot patch fetchLatestWaWebVersion.ts: expected line not found." }
    $waVersionText = $waVersionText.Replace("responseType: 'json',", "responseType: 'json',`n      timeout: 5000,")
    $patched = $true
}
if ($waVersionText.Contains('fetchLatestBaileysVersion')) {
    if (-not $waVersionText.Contains('(await fetchLatestBaileysVersion()).version as WAVersion')) { throw "Cannot patch fetchLatestWaWebVersion.ts: expected fallback not found." }
    $waVersionText = $waVersionText.Replace("(await fetchLatestBaileysVersion()).version as WAVersion", "[2, 3000, 1043857760] as WAVersion")
    $waVersionText = $waVersionText.Replace("import { fetchLatestBaileysVersion, WAVersion } from 'baileys';", "import { WAVersion } from 'baileys';")
    $patched = $true
}
if ($patched) {
    Write-Host "Patching the WhatsApp version fetch (5s timeout + pinned fallback version)..."
    $waVersionText | Set-Content $waVersionFile -Encoding utf8
}

# Rebuild when dist/main.js is missing or when the database provider
# changed (the Prisma client and migrations are provider-specific).
$providerMarker = Join-Path $EvolutionDir '.evolution-build-provider'
$builtProvider = ''
if (Test-Path $providerMarker) { $builtProvider = (Get-Content $providerMarker -Raw).Trim() }
if ((-not (Test-Path 'dist\main.js')) -or ($builtProvider -ne 'mysql') -or $patched) {
    Write-Host "Creating the evolution database in MySQL (if missing)..."
    php (Join-Path $BackendDir 'artisan') tinker --execute "DB::statement('CREATE DATABASE IF NOT EXISTS evolution');"
    if ($LASTEXITCODE -ne 0) { throw "Could not create the evolution database. Is MySQL running on 127.0.0.1:3307 (the same MySQL Laravel uses)?" }

    Write-Host "Checking the MySQL storage engine..."
    $storageEngine = php (Join-Path $BackendDir 'artisan') tinker --execute "echo DB::selectOne('SELECT @@GLOBAL.default_storage_engine AS engine')->engine;"
    if ($LASTEXITCODE -ne 0) { throw "Could not read the MySQL default storage engine." }
    if ($storageEngine.Trim() -ne 'InnoDB') {
        throw "MySQL default storage engine is '$($storageEngine.Trim())'. Evolution migrations require InnoDB. Set default-storage-engine=InnoDB under [mysqld] in MySQL's my.ini and restart MySQL. Then back up and recreate only the evolution database before rerunning this script; its existing tables were created with the wrong engine."
    }

    $myisamTables = php (Join-Path $BackendDir 'artisan') tinker --execute "echo DB::selectOne('SELECT COUNT(*) AS table_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? AND ENGINE = ?', ['evolution', 'BASE TABLE', 'MyISAM'])->table_count;"
    if ($LASTEXITCODE -ne 0) { throw "Could not check the storage engines of tables in the evolution database." }
    if ([int]$myisamTables.Trim() -gt 0) {
        throw "The evolution database still contains $($myisamTables.Trim()) MyISAM table(s), which do not have the foreign keys expected by Evolution migrations. Back up and recreate only the evolution database, then rerun this script."
    }

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
