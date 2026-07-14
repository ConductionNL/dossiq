# Proposal: iv3-taakveld-2023-refinement

## Why

`lib/Settings/iv3_taakvelden.json` (`iv3-bbv-v1`, shipped by `iv3-case-cost-reporting`) documents
its own known limitation: *"CBS's 2023 refinement survey split the Wmo/Jeugd subcodes under
taakveld 6 into finer codes... This change ships the pre-refinement... code set... A municipality
that has already adopted the finer 2023 codes will need that follow-up before this feature is
accurate for their taakveld 6 reporting."* (design.md, `Known limitation` section).

Since 2023, Dutch municipalities are **required** (per the official Iv3-Informatievoorschrift) to
report Wmo/Jeugd costs on the refined codes — the pre-2023 codes `6.71`, `6.72`, `6.81`, `6.82` are
no longer accepted for the begroting/jaarrekening. Procest's shipped taakveld list is therefore
inaccurate for any municipality using it today.

## What Changes

- **REQ-IV3R-001**: `iv3_taakvelden.json` bumps to `iv3-bbv-v2` and adds a `geldigVanaf` field
  (`"2023-01-01"`). Source: the official Rijksoverheid *"Iv3-Informatievoorschrift Gemeenten en
  Gemeenschappelijke regelingen 2023 1.0"* PDF (`rijksoverheid.nl`, section 1 "Belangrijkste
  wijzigingen" blad 4-5 + the per-taakveld definitions blad 27-34) and the accompanying *"Veel
  gestelde vragen verfijning Iv3 jeugd en Wmo"* FAQ PDF — both retrieved and read directly
  (not from secondary/derivative sources) 2026-07-14. Full citation + verbatim excerpts in
  `design.md`.
- **REQ-IV3R-002**: The 4 pre-2023 taakveld-6 codes (`6.71`, `6.72`, `6.81`, `6.82`) are marked
  `"deprecated": true` — they remain resolvable (`isValidCode()`/`labelFor()` unchanged) so cases
  already classified under them keep working.
- **REQ-IV3R-003**: The 18 official 2023-refinement codes are added under taakveld 6: `6.71a-d`
  (was `6.71`), `6.72a-d` + `6.73a-c` + `6.74a-c` (was `6.72` — ten successor codes, not four; the
  old `6.72` "Maatwerkdienstverlening 18-" catch-all covered ambulant, dagbesteding, verblijf, GGZ
  and crisis jeugdhulp, which the refinement separates into distinct codes), `6.81a-b` (was
  `6.81`), `6.82a-b` (was `6.82`). Each carries an `aggregatesUnder` pointer to its pre-2023 parent
  code.
- **REQ-IV3R-004**: Two taakvelden were renamed (not split) in the same official 2023
  informatievoorschrift: `6.2` "Wijkteams" → "Toegang en eerstelijnsvoorzieningen", `6.4`
  "Begeleide participatie" → "WSW en beschut werk". Updated in place (same codes, corrected
  labels) since they're part of the same verified source and directly adjacent to the codes this
  change already touches.
- **REQ-IV3R-005**: `Iv3TaakveldList` gains `isDeprecated(string $code): bool`,
  `aggregationKeyFor(string $code): string`, and `geldigVanaf(): string`. `allTaakvelden()`'s
  flattened shape gains `deprecated` and `aggregatesUnder` fields.
- **REQ-IV3R-006**: `Iv3ReportService::accumulateBuckets()` buckets by
  `Iv3TaakveldList::aggregationKeyFor($taakveld)` instead of the raw code, so a case classified
  under a deprecated pre-2023 code and a case classified under one of its 2023-refinement
  successors land in the same quarterly report bucket — keeping trend continuity across the
  refinement instead of fragmenting one municipality's Wmo/Jeugd reporting into 22 near-empty
  buckets the moment they adopt the new codes.

## Capabilities

### New Capabilities

- `iv3-taakveld-2023-refinement`: the 2023 BBV/Iv3 Wmo/Jeugd taakveld-6 refinement, deprecated-code
  backward compatibility, and cross-refinement aggregation.

## Impact

- **Backend**: `lib/Settings/iv3_taakvelden.json` (data only — 18 new taakvelden, 2 relabeled, 4
  deprecated); `lib/Service/Iv3TaakveldList.php` (3 new methods, flattened shape gains 2 fields);
  `lib/Service/Iv3ReportService.php` (`accumulateBuckets()` buckets by aggregation key).
- **Frontend**: none — `Iv3ReportController::taakvelden()` passes `allTaakvelden()` through
  verbatim; the new fields flow to the existing picker/reference-list consumers automatically, no
  Vue change required.
- **Dependencies**: none added.
