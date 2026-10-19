param([string] $Repo = 'IZKGMD/GD-2.209-Reversed')
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $root
try {
    if (!(Get-Command 'gh' -ErrorAction SilentlyContinue)) { throw 'Install GitHub CLI: https://cli.github.com/' }
    if (!(Get-Command 'git' -ErrorAction SilentlyContinue)) { throw 'Install Git first.' }
    & gh auth status
    if ($LASTEXITCODE -ne 0) { throw 'Run gh auth login first.' }
    if (!(Test-Path '.git')) { & git init -b main }
    & git add README.md docs data ghidra tools scripts tests .gitignore LICENSE NOTICE
    if ($LASTEXITCODE -ne 0) { throw 'git add failed' }
    & git diff --cached --quiet
    if ($LASTEXITCODE -ne 0) {
        & git commit -m 'Initial GD 2.209 reverse-engineering research workspace'
        if ($LASTEXITCODE -ne 0) { throw 'git commit failed; configure git user.name and user.email first' }
    }
    # gh repo create fails safely if target already exists. Never create a public repo.
    & gh repo create $Repo --private --source . --remote origin --push
    if ($LASTEXITCODE -ne 0) { throw 'GitHub repository creation failed' }
    Write-Host "Created PRIVATE repo: https://github.com/$Repo"
} finally { Pop-Location }
