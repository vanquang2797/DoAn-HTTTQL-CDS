param(
    [Parameter(Mandatory=$true)]
    [ValidateSet('api', 'queue', 'schedule', 'web')]
    [string]$Service
)
$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path $PSScriptRoot -Parent
if ($Service -eq 'web') {
    & (Join-Path $taskRoot 'frontend/scripts/flutter.ps1') run -d chrome --web-hostname=127.0.0.1 --web-port=5173 --dart-define=BABI_API_URL=http://127.0.0.1:8000/api
    exit $LASTEXITCODE
}
if ($Service -eq 'queue') {
    Push-Location (Join-Path $taskRoot 'backend')
    try { php -d extension=gd artisan queue:work --tries=3 --timeout=80 }
    finally { Pop-Location }
    exit
}
if ($Service -eq 'schedule') {
    Push-Location (Join-Path $taskRoot 'backend')
    try { php artisan schedule:work }
    finally { Pop-Location }
    exit
}
# Laravel's router expects cwd to be public/. Direct PHP invocation applies upload
# INI settings to the server process instead of only the artisan launcher.
$taskRouter = Join-Path $taskRoot 'backend/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'
Push-Location (Join-Path $taskRoot 'backend/public')
try {
    php -d upload_max_filesize=50M -d post_max_size=512M -d max_file_uploads=10 -S 127.0.0.1:8000 $taskRouter
} finally { Pop-Location }
