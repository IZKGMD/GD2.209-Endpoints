## PHP endpoint implementation

The actual MuchoCore server code is now included under [`server/`](server/):

- [52 PHP endpoint files](server/public/database/)
- [Endpoint-to-controller map](server/ENDPOINTS.md)
- [Controllers and services](server/src/)
- [Database schema and migrations](server/database/)
- [Setup and validation](server/README.md)

This is a pinned source import from `IZKGMD/GMDmucho-core` commit
`856a864745462ceac687d8963ebb1a7ed82e4002`. The copied server logic keeps its
authentication, permission checks and database operations. PHP entrypoint
files forward requests through the included MuchoCore front controller.

## Endpoint research (GD 2.209)

The previously prepared endpoint research is now tracked in this repository:

- [52-endpoint inventory](research/2209/data/endpoints_2209.json)
- [Native request fragments and response parser findings](research/2209/docs/NATIVE_FINDINGS.md)
- [PE scanner and local Ghidra workflow](research/2209/README.md)
- [Protocol verification checklist](research/2209/docs/PROTOCOL_CHECKLIST.md)

These files were imported from `GD-2.209-Reversed.zip` prepared on 2026-10-18.
The routing counts in that inventory describe its dated MuchoCore snapshot.
The research directory contains the protocol catalog and analysis tools.
The PHP server handlers are in [`server/`](server/). A completed game
decompilation is not included. The large game archive described below
is a separate deliverable and is still not uploaded.

Offline scanner checks:

```sh
cd research/2209
python -m unittest discover -s tests -v
```

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
