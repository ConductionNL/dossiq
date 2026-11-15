---
kind: code
depends_on: []
---

# Proposal: seeded-case-types-are-published

## Why

On the review instance (10 Oct, finding B3) 13 of the 23 shipped case types could
not be chosen in the new case form, among them "Sloopmelding" and "Omgevingsvergunning
kleine bouwactiviteit". The case type picker offers only `isDraft: false`, and
`caseType.isDraft` defaults to `true`. These 13 were seeded without `isDraft`, so
OpenRegister stored the default and every one of them is a draft:

- `dossiq_register.json`: the four supplier case types (`leverancier-*`)
- `register.d/50-subsidie.json`: `zaaktype-innovatiefonds-2026`, `zaaktype-cultuur-subsidie-2026`
- `vth_seed_data.json`: the six VTH case types
- `case_flow_seed_data.json`: `omgevingsvergunning-kleinbouw`

Nothing errored: a case type that cannot be picked looks the same as one that is
not there.

## What Changes

- Every shipped case type states `isDraft`; the 13 above say `false`.
- A new repair step, `PublishSeededCaseTypes`, sets `isDraft: false` on exactly
  those 13 case types (by seed slug) on an existing install, once.

## Impact

- Seeds: `lib/Settings/dossiq_register.json` (objects only, no schema change),
  `lib/Settings/register.d/50-subsidie.json`, `lib/Settings/vth_seed_data.json`,
  `lib/Settings/case_flow_seed_data.json`.
- `lib/Repair/PublishSeededCaseTypes.php`, registered in `appinfo/info.xml` in
  both `<post-migration>` and `<install>`, after the case type seeds.
- No schema version moves: only seed objects change.
