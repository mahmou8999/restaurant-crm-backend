# start-evolution.ps1 — تشغيل Evolution API من الكود مباشرة (Node.js) من غير Docker.
#
# الاستخدام: من PowerShell داخل مجلد الباك اند:
#   .\scripts\start-evolution.ps1
#
# السكربت بيقرأ EVOLUTION_API_KEY من .env بتاع Laravel وبيستخدمه
# كمفتاح لـ Evolution (AUTHENTICATION_API_KEY)، فالمفتاحين يفضلوا
# متطابقين. سيب النافذة دي مفتوحة طول ما انت عايز الواتساب شغال.

$ErrorActionPreference = 'Stop'

$BackendDir = Split-Path -Parent $PSScriptRoot
$EnvFile = Join-Path $BackendDir '.env'

if (-not (Test-Path $EnvFile)) {
    throw "ملف .env مش موجود في $BackendDir — انسخ .env.example لـ .env الأول وظبّط EVOLUTION_API_KEY."
}

$apiKey = $null
foreach ($line in Get-Content $EnvFile) {
    if ($line -match '^\s*EVOLUTION_API_KEY\s*=\s*(.+?)\s*$') {
        $apiKey = $Matches[1].Trim().Trim('"').Trim("'")
        break
    }
}
if ([string]::IsNullOrWhiteSpace($apiKey)) {
    throw "EVOLUTION_API_KEY فاضي في .env — حط مفتاح فيه الأول."
}

# Node.js 20+
try {
    $nodeRaw = (node --version).Trim()
} catch {
    throw "Node.js مش متسطب — نزّله من https://nodejs.org/ (إصدار 20 أو أعلى)."
}
$nodeMajor = [int]($nodeRaw.TrimStart('v').Split('.')[0])
if ($nodeMajor -lt 20) {
    throw "Node.js عندك $nodeRaw — لازم إصدار 20 أو أعلى."
}

try {
    $null = git --version
} catch {
    throw "Git مش متسطب — نزّله من https://git-scm.com/."
}

$EvolutionDir = Join-Path (Split-Path -Parent $BackendDir) 'evolution-api'

if (-not (Test-Path $EvolutionDir)) {
    Write-Host "بنزّل Evolution من GitHub..."
    git clone https://github.com/evolution-foundation/evolution-api.git $EvolutionDir
}

Set-Location $EvolutionDir

if (-not (Test-Path 'node_modules')) {
    Write-Host "بنثبّت حزم Evolution (npm install)..."
    npm install
}

# ظبط .env بتاع Evolution بالمفتاح بتاع Laravel
$EvolutionEnv = Join-Path $EvolutionDir '.env'
$wanted = [ordered]@{
    'SERVER_TYPE'            = 'http'
    'SERVER_PORT'            = '8080'
    'AUTHENTICATION_API_KEY' = $apiKey
    'DATABASE_ENABLED'       = 'false'
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

if (-not (Test-Path 'dist')) {
    Write-Host "بنبني Evolution (npm run build)..."
    npm run build
}

Write-Host ""
Write-Host "Evolution هيشتغل على http://127.0.0.1:8080 — سيب النافذة دي مفتوحة."
Write-Host "بعدها اطلب الـ QR من صفحة WhatsAppConnect في الفرونت (برقم تجريبي الأول)."
Write-Host ""
npm run start:prod
