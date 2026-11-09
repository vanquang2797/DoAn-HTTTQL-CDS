$ErrorActionPreference = 'Stop'
$flutterCandidates = @()
if ($env:FLUTTER_ROOT) {
    $flutterCandidates += Join-Path $env:FLUTTER_ROOT 'bin\flutter.bat'
}
$flutterCommand = Get-Command flutter -ErrorAction SilentlyContinue
if ($flutterCommand) { $flutterCandidates += $flutterCommand.Source }
$flutterCandidates += 'C:\src\flutter\flutter\bin\flutter.bat'
$flutterExecutable = $null
foreach ($candidate in $flutterCandidates) {
    $sdkDirectory = Split-Path (Split-Path $candidate -Parent) -Parent
    if ((Test-Path -LiteralPath $candidate) -and (Test-Path -LiteralPath (Join-Path $sdkDirectory 'packages\flutter_tools\pubspec.yaml'))) {
        $flutterExecutable = $candidate
        break
    }
}
if (-not $flutterExecutable) { throw 'A complete Flutter SDK was not found. Install Flutter or set FLUTTER_ROOT to the SDK directory.' }
Push-Location (Join-Path $PSScriptRoot '..')
try {
    & $flutterExecutable @args
    $toolExitCode = $LASTEXITCODE
} finally { Pop-Location }
exit $toolExitCode
