param(
    [string]$ZipPath = (Join-Path $PSScriptRoot 'MuchoCore_GD2209_FULL.zip'),
    [string]$Tag = 'gd2.209-windows-test'
)
$ErrorActionPreference = 'Stop'
$Repo = 'IZKGMD/gd'
$ExpectedSize = 280459409
$ExpectedSha256 = 'daf9ee10704893383e081c7427d2d1d8e97577cc4eea3c915b7d7f9bec48fb64'

function Assert-Exit([string]$Operation) {
    if ($LASTEXITCODE -ne 0) { throw "$Operation failed with exit code $LASTEXITCODE" }
}
if (-not (Get-Command gh -ErrorAction SilentlyContinue)) {
    throw 'Install GitHub CLI: winget install --id GitHub.cli -e'
}
if (-not (Test-Path -LiteralPath $ZipPath -PathType Leaf)) {
    throw "Archive missing: $ZipPath. Download or reassemble the four ZIP parts first."
}
$file = Get-Item -LiteralPath $ZipPath
if ($file.Length -ne $ExpectedSize) {
    throw "File size mismatch: $($file.Length) != $ExpectedSize"
}
$sha = (Get-FileHash -Algorithm SHA256 -LiteralPath $ZipPath).Hash.ToLowerInvariant()
if ($sha -ne $ExpectedSha256) { throw "SHA-256 mismatch: $sha" }
Write-Host "Verified full game archive: $sha" -ForegroundColor Green

& gh auth status
if ($LASTEXITCODE -ne 0) {
    & gh auth login --hostname github.com --git-protocol https --web
    Assert-Exit 'Browser sign-in'
}
$repoPrivate = (& gh api "repos/$Repo" --jq '.private' | Out-String).Trim()
Assert-Exit 'Repository visibility check'
if ($repoPrivate -ne 'true') { throw "REFUSED: $Repo is not private" }

$assetName = 'MuchoCore_GD2209_FULL.zip'
$releaseData = $null
try {
    $releaseData = & gh release view $Tag --repo $Repo --json assets 2>$null | ConvertFrom-Json
} catch {}
if ($LASTEXITCODE -eq 0 -and $null -ne $releaseData) {
    $existing = @($releaseData.assets | Where-Object { $_.name -eq $assetName })
    if ($existing.Count -gt 0) {
        throw "Release asset already exists. Not overwriting: $assetName"
    }
    & gh release upload $Tag $ZipPath --repo $Repo
    Assert-Exit 'Upload release asset'
} else {
    & gh release create $Tag $ZipPath --repo $Repo --title 'MuchoCore GD 2.209 Windows FULL' --notes-file (Join-Path $PSScriptRoot 'RELEASE_NOTES.md')
    Assert-Exit 'Create release and upload asset'
}
& gh release view $Tag --repo $Repo --json url,assets
Assert-Exit 'Verify release'
Write-Host 'Finished uploading complete Windows build to the PRIVATE repository.' -ForegroundColor Green
