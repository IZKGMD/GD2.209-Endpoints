param(
    [Parameter(Mandatory=$true)][string] $Exe,
    [Parameter(Mandatory=$true)][string] $GhidraHome,
    [int] $MaxFunctions = 0,
    [int] $TimeoutSeconds = 60
)
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$exePath = (Resolve-Path $Exe).Path
$exeHash = (Get-FileHash -Algorithm SHA256 -Path $exePath).Hash.ToLowerInvariant()
$expected = '524265b3781596fcc7c9e4d60dc5ac2d0a4cd9530ee3ea637e123f9ac1f9b6a8'
if ($exeHash -ne $expected) {
    throw "This tool is calibrated to the known unmodified 2.209 EXE. SHA-256 mismatch: $exeHash"
}
$headless = Join-Path $GhidraHome 'support\analyzeHeadless.bat'
if (!(Test-Path $headless)) {
    throw "Ghidra headless launcher not found: $headless"
}
if ($MaxFunctions -lt 0 -or $TimeoutSeconds -lt 1) {
    throw 'Invalid MaxFunctions or TimeoutSeconds.'
}
$projectLocation = Join-Path $root 'work\ghidra_project'
$output = Join-Path $root 'decompiled'
$scriptPath = Join-Path $root 'ghidra'
New-Item -ItemType Directory -Force -Path $projectLocation,$output | Out-Null
# Use -import for first run, and -process for subsequent runs. If a user previously
# imported other files into the project, matching by file name is intentional.
$projMarker = Join-Path $projectLocation 'GD2209.gpr'
if (Test-Path $projMarker) {
    $args = @($projectLocation, 'GD2209', '-process', (Split-Path $exePath -Leaf), '-scriptPath', $scriptPath,
      '-postScript', 'ExportDecompiled.py', $output, "$MaxFunctions", "$TimeoutSeconds", '-max-cpu', '2')
} else {
    $args = @($projectLocation, 'GD2209', '-import', $exePath, '-scriptPath', $scriptPath,
      '-postScript', 'ExportDecompiled.py', $output, "$MaxFunctions", "$TimeoutSeconds", '-max-cpu', '2')
}
Write-Host "Local Ghidra analysis of verified 2.209 executable; output: $output"
& $headless @args
if ($LASTEXITCODE -ne 0) { throw "Ghidra exited with status $LASTEXITCODE" }
