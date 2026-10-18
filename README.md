# MuchoCore · Geometry Dash 2.209 (Windows)

**This is a PRIVATE repository for compatibility testing.**

## Build status

- Full archive: `MuchoCore_GD2209_FULL.zip` (280,459,409 bytes)
- SHA-256: `daf9ee10704893383e081c7427d2d1d8e97577cc4eea3c915b7d7f9bec48fb64`
- Contains original game resources and DLLs plus a modified Windows x64
  `GeometryDash.exe` that points API calls to `https://muchogdps.space`.
- ZIP archive integrity and static executable patch checks have passed.
- **GitHub Release asset NOT YET UPLOADED**. The connected GitHub integration
  here cannot upload binary release assets.

## Publish the complete archive

1. Download `MuchoCore_GD2209_FULL.zip` from your own ChatGPT deliverable
   (or join its four `.001`–`.004` parts).
2. Install GitHub CLI: `winget install --id GitHub.cli -e`.
3. Download this repository's `UPLOAD_TO_GD.ps1` and `RELEASE_NOTES.md`
   alongside the ZIP. On Windows, execute:

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\UPLOAD_TO_GD.ps1
   ```

The script verifies the archive size and SHA-256, checks that this repo is
**private**, authenticates via the GitHub CLI, and publishes the complete ZIP
to GitHub Releases. It refuses public repositories and does not overwrite an
existing same-named asset.

No game files have been committed to Git history. Avoid publicly distributing
original proprietary game assets.
