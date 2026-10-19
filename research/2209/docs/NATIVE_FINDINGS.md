# GD 2.209 native executable findings (reference digest)

Original research and attribution: [MuchoCore analysis dated 2026-10-17](https://github.com/IZKGMD/GMDmucho-core/blob/main/docs/GD_2209_NATIVE_EXE_FINDINGS.md). This document is a curated summary, not a new decompilation performed in this workspace.

## Evidence identity

- `GeometryDash(2).exe`, SHA-256 `524265b3781596fcc7c9e4d60dc5ac2d0a4cd9530ee3ea637e123f9ac1f9b6a8`
- Windows x64 PE32+ with `.text`, `.rdata`, `.data`, `.pdata`, `.rsrc`, `.reloc`
- Source project reports 111 NUL-terminated HTTPS API URL strings in `.rdata` (not 111 new endpoints).

## Confirmed static request format fragments

| Endpoint | Fragment recovered from native constant strings |
|---|---|
| `adminGetReportQueue.php` | `&page=%i&sort=%i&mode=%i&flags=1&secret=%s` |
| `adminGetReportedContent.php` | `&targetAccountID=%i&page=%i&sort=%i&ctype=%i&secret=%s` |
| `adminGetUserHistory.php` | `&targetAccountID=%i&page=%i&secret=%s` |
| `adminGetAbuseInfo.php` | `&targetAccountID=%i&page=%i&history=%i&secret=%s` |
| `adminGetAccountReview.php` | `&targetAccountID=%i&secret=%s` |
| `adminClearReportedContent.php` | `&targetAccountID=%i&items=%s&secret=%s` |
| `adminThreadAction.php` | `&threadID=%i&action=%s&messageID=%lli&secret=%s` |
| `syncGJFollowing.php` | `&follows=%s&ack=1&secret=%s` |
| `getGJFollowing.php` | `&ack=1&secret=%s` |
| `getGJSongUsage.php` | `&songID=%i&secret=%s` |
| `reportGJContent.php` | `type=%i&targetID=%i&special=%i&reasons=%s&accountID=%i&gjp2=%s&secret=%s` |

These are *format string fragments*, not full requests; standard client fields are built elsewhere.

## Moderation queue binary protocol, native parser

- `AdminReportQueueEntry` MSVC RTTI located via `.?AVAdminReportQueueEntry@@`.
- Reference image VA `0x140242a29`: client parser searches for a `#` separator; absent `#` means failure.
- Prefix before `#` is **three colon-separated integers**: `total:offset:pageSize`.
- Suffix after `#` is `|`-separated entries; each entry is colon-positional with at least nine fields.
- Empty queue reply format: `0:0:20#`, **not** `-2`.
- Known entry indices: `0` account ID, `1` username, `2` distinct reported items, `4` anonymous reporters, `5` common report category, `8` direct profile reports, `12` abuse score, `13` auto text flag, `16` flag count, `19` distinct reporting accounts.
- Unmapped fields and some UI filters remain unknown. Do not generate false success results to fill those gaps.

## Reality checks

The native report queue is **account-centric**, not a flat list of reported levels. An actual 2.209 client captured `POST /database/adminGetReportQueue.php` with `gameVersion=22`, `binaryVersion=51`; the older server returned `-1`. At the time of the referenced analysis, successful native client acceptance still needed verification.

This workspace is for reproducible investigation, not claims that the whole game has been decompiled, rebuilt, or made fully compatible.
