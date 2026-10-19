# GD 2.209 Reversed — research workspace

**Work in progress. Not a complete, compilable source-code decompilation.**

This separate workspace records independently produced research into Windows Geometry Dash 2.209, with scripts to analyze a user-supplied, legally obtained executable **locally** using Ghidra. It does not include the game's EXE, game assets, extracted proprietary code, or buildable Geometry Dash source.

## Known Windows build

| Property | Value |
|---|---|
| SHA-256 | `524265b3781596fcc7c9e4d60dc5ac2d0a4cd9530ee3ea637e123f9ac1f9b6a8` |
| Format | Windows x86-64 PE32+ |
| Size | 13,158,400 bytes |
| Endpoints in current 2.209 inventory | 52 |
| Routable in MuchoCore snapshot | 27 (25 missing; **not** native wire verification) |

Source research: [MuchoCore native EXE findings](https://github.com/IZKGMD/GMDmucho-core/blob/main/docs/GD_2209_NATIVE_EXE_FINDINGS.md), [coverage inventory](https://github.com/IZKGMD/GMDmucho-core/blob/main/docs/GD_2209_COVERAGE.md).

## Windows — local decompilation via Ghidra

1. Install Java (as required by the version of Ghidra you use), [Ghidra](https://github.com/NationalSecurityAgency/ghidra/releases), Git and Python 3.10+.
2. Place **your own** unmodified 2.209 `GeometryDash.exe` outside this project, e.g. `C:\GD209\GeometryDash.exe`.
3. From PowerShell in this repository, run:

```powershell
python .\tools\scan_binary.py 'C:\GD209\GeometryDash.exe' --output .\evidence\binary_scan.json
.\scripts\decompile_windows.ps1 -Exe 'C:\GD209\GeometryDash.exe' -GhidraHome 'C:\Tools\ghidra'
```

If Ghidra is installed somewhere else, change `-GhidraHome`. The script verifies the exact binary hash before invoking Ghidra's headless analysis and then writes per-function `.c` **pseudocode**, not a buildable project, under `decompiled/`. Ghidra uses its own C-like representation; symbols, parameter types, class layout and semantics often require manual reconstruction.

To test just a subset first:

```powershell
.\scripts\decompile_windows.ps1 -Exe 'C:\GD209\GeometryDash.exe' -GhidraHome 'C:\Tools\ghidra' -MaxFunctions 20
```

The Ghidra project is preserved in `work/ghidra_project/` for repeated investigations. Running the exporter again skips already exported functions. It is normal for functions to remain unnamed or fail decompilation.

## Directory layout

```text
GD-2.209-Reversed/
  data/endpoints_2209.json         Known 52 2.209 endpoints with MuchoCore route snapshot
  docs/NATIVE_FINDINGS.md          Curated original research summary, links and confidence
  docs/PROTOCOL_CHECKLIST.md       Remaining work before claiming compatibility
  ghidra/ExportDecompiled.py       Jython script for per-function Ghidra pseudocode
  tools/scan_binary.py             PE verification, original API URL inventory
  scripts/decompile_windows.ps1   Run Ghidra Headless safely on local input
  scripts/create_private_repo.ps1  Publish only the research/tools to a new PRIVATE GitHub repo
  tests/                          Offline tests with synthetic PE fixture
  decompiled/                     Generated locally; ignored by Git
  evidence/                       Generated locally; ignored by Git
```

## Create a separate PRIVATE GitHub repository

The connected GitHub API in this ChatGPT session did not expose repository creation. From PowerShell on your own machine, with `gh auth login` completed:

```powershell
.\scripts\create_private_repo.ps1 -Repo 'IZKGMD/GD-2.209-Reversed'
```

This creates and pushes the **research project**, not the original game or generated output. `decompiled/`, `evidence/`, `work/` and `GeometryDash.exe` are deliberately Git-ignored. Do **not** publicly publish the proprietary executable or bulk decompiler output without resolving applicable permissions and rights. The MIT license here applies **only** to newly written tooling.

## Important accuracy boundary

- `52 endpoint names` is an inventory, not `52 completely implemented endpoints`.
- `27 registered routes` is a snapshot of MuchoCore main, not evidence of parity.
- Static strings and Ghidra pseudocode do not prove live request/response semantics.
- Address annotations may apply only to the exact SHA-256 build above.
- The `0:0:20#` empty moderation queue framing was recovered from native client parser analysis; other fields are still being mapped.

`python -m unittest discover -s tests -v` checks the scanner without a copyrighted executable.
