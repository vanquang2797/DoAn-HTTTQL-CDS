param(
    [switch]$SkipFlutter,
    [switch]$SkipWebBuild,
    [switch]$SkipHttpSmoke,
    [switch]$SkipBrowserSmoke
)

$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path $PSScriptRoot -Parent

function Invoke-Check {
    param(
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][scriptblock]$Command
    )

    Write-Host "`n=== $Name ===" -ForegroundColor Cyan
    & $Command
    if ($LASTEXITCODE -ne 0) {
        throw "$Name failed with exit code $LASTEXITCODE."
    }
}

if (-not $SkipFlutter) {
    $flutter = Join-Path $taskRoot 'frontend/scripts/flutter.ps1'
    Push-Location (Join-Path $taskRoot 'frontend')
    try {
        Invoke-Check 'Flutter static analysis' { & $flutter analyze --no-pub }
        Invoke-Check 'Flutter tests' {
            & $flutter test --no-pub --dart-define=BABI_DESIGN_PREVIEW=true --dart-define=BABI_EXPORT_RENDERS=true
        }
        if (-not $SkipWebBuild) {
            Invoke-Check 'Flutter web release build' {
                & $flutter build web --release --no-pub --output=build/auto-check-web
            }
        }
    }
    finally {
        Pop-Location
    }
}

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    throw 'PHP was not found on PATH; start the XAMPP shell or add its PHP directory to PATH.'
}
$python = if ($env:BABI_PYTHON) { $env:BABI_PYTHON } else {
    (Get-Command python -ErrorAction SilentlyContinue).Source
}
if (-not $python -and (-not $SkipHttpSmoke -or -not $SkipBrowserSmoke)) {
    throw 'Python was not found on PATH; set BABI_PYTHON or install Python.'
}

if (-not $SkipHttpSmoke -or -not $SkipBrowserSmoke) {
    $oldPython = $env:BABI_PYTHON
    $env:BABI_PYTHON = $python
    try {
        if (-not $SkipHttpSmoke) {
            Invoke-Check 'Laravel HTTP + real queue smoke (isolated SQLite)' {
                & $python (Join-Path $taskRoot 'backend/tests/smoke_http.py')
            }
        }
        if (-not $SkipBrowserSmoke) {
            Invoke-Check 'Chrome fake-camera end-to-end smoke' {
                & $python (Join-Path $taskRoot 'backend/tests/smoke_browser.py')
            }
        }
    }
    finally {
        if ($null -eq $oldPython) {
            Remove-Item Env:BABI_PYTHON -ErrorAction SilentlyContinue
        }
        else {
            $env:BABI_PYTHON = $oldPython
        }
    }
}

Push-Location (Join-Path $taskRoot 'backend')
try {
    Invoke-Check 'Laravel feature tests (SQLite in-memory)' {
        $testingEnvironment = @{
            APP_ENV = 'testing'
            APP_MAINTENANCE_DRIVER = 'file'
            BCRYPT_ROUNDS = '4'
            CACHE_STORE = 'array'
            DB_CONNECTION = 'sqlite'
            DB_DATABASE = ':memory:'
            MAIL_MAILER = 'array'
            PULSE_ENABLED = 'false'
            QUEUE_CONNECTION = 'sync'
            SESSION_DRIVER = 'array'
            TELESCOPE_ENABLED = 'false'
        }
        $previousEnvironment = @{}
        foreach ($key in $testingEnvironment.Keys) {
            $previousEnvironment[$key] = [Environment]::GetEnvironmentVariable($key, 'Process')
            Set-Item "Env:$key" $testingEnvironment[$key]
        }
        try {
            & php -d extension=gd vendor/bin/phpunit --no-configuration --test-suffix=Test.php tests
        }
        finally {
            foreach ($key in $testingEnvironment.Keys) {
                if ($null -eq $previousEnvironment[$key]) {
                    Remove-Item "Env:$key" -ErrorAction SilentlyContinue
                }
                else {
                    Set-Item "Env:$key" $previousEnvironment[$key]
                }
            }
        }
    }
}
finally {
    Pop-Location
}

Write-Host "`nAll selected Babi checks passed." -ForegroundColor Green
