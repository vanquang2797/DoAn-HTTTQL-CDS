param([switch]$Rebuild)

$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path $PSScriptRoot -Parent
$backend = Join-Path $taskRoot 'backend'
$frontend = Join-Path $taskRoot 'frontend'
$database = Join-Path $backend 'database/family-test.sqlite'
$catalog = Join-Path $backend 'database/catalogs/vietnam-epi-52-2025.json'
$php = 'C:\xampp\php\php.exe'
$apiOutLog = Join-Path $taskRoot 'tmp/family-test-api.out.log'
$apiErrorLog = Join-Path $taskRoot 'tmp/family-test-api.err.log'
$webOutLog = Join-Path $taskRoot 'tmp/family-test-web.out.log'
$webErrorLog = Join-Path $taskRoot 'tmp/family-test-web.err.log'

if (-not (Test-Path -LiteralPath $php)) { throw "PHP not found at $php" }
if (-not (Test-Path -LiteralPath $catalog)) { throw "Vaccine catalog not found: $catalog" }
foreach ($port in 5173, 8000) {
    $listeners = @(Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue)
    foreach ($listener in $listeners) {
        $process = Get-CimInstance Win32_Process -Filter "ProcessId = $($listener.OwningProcess)"
        $isOurApi = $port -eq 8000 -and $process.Name -eq 'php.exe' -and $process.CommandLine -like "*$(Join-Path $backend 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php')*"
        $isOurWeb = $port -eq 5173 -and $process.Name -eq 'php.exe' -and $process.CommandLine -like '*-S 127.0.0.1:5173*' -and ($process.CommandLine -like '*build\liquid-glass-demo*' -or $process.CommandLine -like "*$(Join-Path $frontend 'build\app-connected')*")
        if ($isOurApi -or $isOurWeb) {
            Stop-Process -Id $listener.OwningProcess -Force
        } else {
            throw "Port $port is occupied by a process outside this app. Stop that process before preparing this test environment."
        }
    }
}
Start-Sleep -Milliseconds 500

New-Item -ItemType Directory -Force (Split-Path $database -Parent), (Join-Path $taskRoot 'tmp') | Out-Null
if (-not (Test-Path -LiteralPath $database)) { New-Item -ItemType File -Path $database | Out-Null }

# Keep the normal MySQL .env untouched. These process-local settings apply only to
# this script and the local test servers it starts.
$env:APP_ENV = 'local'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $database
# Honor MAIL_* from backend/.env (or the caller). Do not force real SMTP
# back to log when starting the family demo.
$env:BABI_FRONTEND_URL = 'http://127.0.0.1:5173'
$env:BABI_WEB_ORIGINS = 'http://127.0.0.1:5173,http://localhost:5173'
$env:BABI_VACCINE_CATALOG_VERSION = 'VN-EPI-52-2025'
$env:BABI_LOCAL_TEST_FIXTURES = 'true'
$env:QUEUE_CONNECTION = 'database'

Push-Location $backend
try {
    & $php artisan config:clear
    if ($LASTEXITCODE -ne 0) { throw 'Laravel config:clear failed.' }
    & $php artisan migrate --force
    if ($LASTEXITCODE -ne 0) { throw 'SQLite migrations failed.' }
    $catalogData = Get-Content -LiteralPath $catalog -Raw | ConvertFrom-Json
    $catalogCheck = Join-Path $taskRoot 'tmp/check-family-catalog.php'
    @'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo Illuminate\Support\Facades\DB::table('vaccine_templates')
    ->where('program', 'expanded')->where('schedule_version', $argv[2])->count();
'@ | Set-Content -LiteralPath $catalogCheck -Encoding Ascii
    $existingCatalogCount = [int](& $php $catalogCheck $backend 'VN-EPI-52-2025')
    if ($LASTEXITCODE -ne 0) { throw 'Could not check the local vaccine catalog.' }
    if ($existingCatalogCount -eq 0) {
        & $php artisan babi:import-vaccines $catalog --reviewed
        if ($LASTEXITCODE -ne 0) { throw 'The reviewed Vietnamese EPI catalog could not be imported.' }
    } elseif ($existingCatalogCount -ne $catalogData.templates.Count) {
        throw "The local catalog contains $existingCatalogCount entries; expected $($catalogData.templates.Count). Recreate the disposable SQLite database to recover."
    } else {
        Write-Host "Reviewed catalog already loaded ($existingCatalogCount vaccine schedule entries)."
    }
    & $php artisan db:seed --class=Database\Seeders\MilestoneSeeder --force
    if ($LASTEXITCODE -ne 0) { throw 'Milestone fixtures failed.' }
    & $php artisan db:seed --class=Database\Seeders\FamilyLocalTestSeeder --force
    if ($LASTEXITCODE -ne 0) { throw 'Local family test fixtures failed.' }
} finally {
    Pop-Location
}

if ($Rebuild) {
    & (Join-Path $frontend 'scripts/flutter.ps1') build web --release --no-pub --dart-define=BABI_API_URL=http://127.0.0.1:8000/api -t lib/main.dart -o build/app-connected
    if ($LASTEXITCODE -ne 0) { throw 'Flutter web build failed.' }
} elseif (-not (Test-Path -LiteralPath (Join-Path $frontend 'build/app-connected/main.dart.js'))) {
    throw 'The connected Flutter web build is missing. Run this script with -Rebuild after installing a working Flutter SDK.'
}

$apiRouter = Join-Path $backend 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'
$apiWorkingDirectory = Join-Path $backend 'public'
$webBuild = Join-Path $frontend 'build/app-connected'
Start-Process -FilePath $php -ArgumentList @('-d', 'upload_max_filesize=50M', '-d', 'post_max_size=512M', '-d', 'max_file_uploads=10', '-S', '127.0.0.1:8000', $apiRouter) -WorkingDirectory $apiWorkingDirectory -WindowStyle Hidden -RedirectStandardOutput $apiOutLog -RedirectStandardError $apiErrorLog | Out-Null
Start-Process -FilePath $php -ArgumentList @('-S', '127.0.0.1:5173', '-t', $webBuild) -WorkingDirectory $webBuild -WindowStyle Hidden -RedirectStandardOutput $webOutLog -RedirectStandardError $webErrorLog | Out-Null
Start-Process -FilePath $php -ArgumentList @('-d', 'extension=gd', 'artisan', 'queue:work', 'database', '--tries=3', '--timeout=80') -WorkingDirectory $backend -WindowStyle Hidden -RedirectStandardOutput (Join-Path $taskRoot 'tmp/family-test-queue.out.log') -RedirectStandardError (Join-Path $taskRoot 'tmp/family-test-queue.err.log') | Out-Null
Start-Process -FilePath $php -ArgumentList @('artisan', 'schedule:work') -WorkingDirectory $backend -WindowStyle Hidden -RedirectStandardOutput (Join-Path $taskRoot 'tmp/family-test-scheduler.out.log') -RedirectStandardError (Join-Path $taskRoot 'tmp/family-test-scheduler.err.log') | Out-Null

$ready = $false
for ($attempt = 0; $attempt -lt 20; $attempt++) {
    Start-Sleep -Milliseconds 500
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8000/up' -TimeoutSec 2
        $page = Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:5173/' -TimeoutSec 2
        if ($response.StatusCode -eq 200 -and $page.StatusCode -eq 200) { $ready = $true; break }
    } catch { }
}
if (-not $ready) { throw "Local servers did not become ready. Check $apiErrorLog and $webErrorLog" }

Write-Host ''
Write-Host 'Local family test app is ready at http://127.0.0.1:5173/'
Write-Host 'Backend health: http://127.0.0.1:8000/up'
Write-Host 'Owner login: babi.owner@example.test'
Write-Host 'Invitee login: babi.viewer@example.test'
Write-Host 'Password for both: BabiLocalTest2026!'
Write-Host 'Email delivery uses MAIL_* from backend/.env or the calling environment.'
Write-Host 'All accounts and the baby profile are synthetic fixtures in backend/database/family-test.sqlite.'
