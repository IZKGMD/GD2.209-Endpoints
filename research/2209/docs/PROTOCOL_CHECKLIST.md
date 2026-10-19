# Native compatibility worklist

Current server-side routing snapshot is in [`../data/endpoints_2209.json`](../data/endpoints_2209.json). Registered routes are **not** automatically native-compatible.

- [ ] Extract and label every client URL reference from the exact Windows EXE.
- [ ] Recover full request field construction per new route; distinguish common fields and endpoint-specific fields.
- [ ] Recover response parsers including delimiters, field ordering, Base64/XOR decoding, and empty/error sentinels.
- [ ] Capture *sanitized* real-client request-key inventories on a private staging instance, without `gjp2`, passwords, tokens, messages or personal data.
- [ ] Verify native client accepts empty and nonempty responses for each endpoint.
- [ ] Confirm `gameVersion`, `binaryVersion` gating including values 22 and 51 respectively.
- [ ] Model 2.209 features including notifications, thread messages, editor sounds, death data, platformer controls, and moderation permissions.
- [ ] Compare current Windows findings with separately obtained Android client behavior.
- [ ] Preserve reproducibility: binary SHA-256, script version, tool version, virtual address and evidence link.
- [ ] Keep research distinct from complete decompilation or copyright ownership claims.

Reference: [MuchoCore verification gates](https://github.com/IZKGMD/GMDmucho-core/blob/main/docs/GD_2209_VERIFICATION_GATES.md).
