# Tasks: bezwaar-decision

This is a **GENERATE-style** change: a partial implementation already exists (committed alongside `bezwaar-beroep-workflow` at `6c45b8b`). Tasks here verify that the live code matches the now-formalized 10-requirement contract. No code changes are introduced; any gap found is filed as a follow-up issue rather than fixed inside this change.

## Schema documentation

- [ ] **T01**: Confirm `bezwaarBesluit` (or equivalent `decision` extension) schema in `lib/Settings/procest_register.json` declares — at minimum — properties `case`, `contestedDecision`, `disposition`, `motivering`, `heroverwegingExNunc`, `advisoryReport`, `followsAdvice`, `deviationReason`, `replacementDecision`, `appealNotice`, `proceskosten`, `decisionDate`, `effectiveDate`, `decisionMaker`, `decisionDocument`, `publishedAt`, `notifiedRecipients`. Required list contains `case`, `contestedDecision`, `disposition`, `motivering`, `decisionDate`, `effectiveDate`. `disposition` enum is exactly `[niet_ontvankelijk, ongegrond, gegrond_handhaven, gegrond_herroepen, gegrond_wijzigen]`. If the current schema still uses the non-canonical 4-value enum (`gegrond, ongegrond, deels_gegrond, niet_ontvankelijk`), record the drift and open a follow-up issue titled "Migrate bezwaarBesluit.disposition to canonical Awb 7:11 enum".

## Capability verification

- [ ] **T02**: Verify per-outcome mandatory fields — load test fixtures (or seed objects) for each of the five `disposition` values and assert: `niet_ontvankelijk` requires `motivering` citing Art. 6:5/6:6/6:7; `gegrond_wijzigen` requires `replacementDecision`; `gegrond_herroepen` and `gegrond_wijzigen` permit `proceskosten.awarded`; `ongegrond` and `gegrond_handhaven` MUST NOT carry `replacementDecision`. Record any deviation; file issue if validation is missing.

- [ ] **T03**: Verify appeals notice (rechtsmiddelenclausule) — call the decision create/update endpoint with an `appealNotice` block missing `competentCourt` or `beroepTerm` and confirm the system either rejects the request or surfaces a "Rechtsmiddelenclausule onvolledig" warning that blocks transition to `published`. Record exact behavior; file issue if no block/warning exists.

- [ ] **T04**: Verify proceskostenvergoeding rules — create a bezwaarBesluit with `disposition = gegrond_herroepen` and `proceskosten.requested = true`. Assert the system requires `proceskosten.awarded` to be explicitly set with `reasoning`. Then attempt to award `proceskosten` on an `ongegrond` decision and assert the system rejects it or warns "Proceskostenvergoeding niet mogelijk: primair besluit niet herroepen". File issue if either rule is missing.

- [ ] **T05**: Verify advisory committee deviation rationale — create a bezwaarBesluit with `advisoryReport` set and `followsAdvice = false` but no `deviationReason`. Assert the system rejects the request with reference to Awb Art. 7:13 lid 7. Record current behavior; file issue if no validation exists.

- [ ] **T06**: Verify decision deadline calculation — create a bezwaar case with `ontvangstdatum = 2026-03-01`. Without verdaging or hoorzitting, the bezwaarBesluit's `decisionDate` validation MUST flag any date later than 2026-04-12. With a hoorzitting recorded, the deadline MUST shift to 2026-05-10 (+4 weeks). With verdaging applied, +6 weeks. Cross-check against `bezwaar-lifecycle`'s `afhandelDeadline` calculation. File issue if the linkage is missing.

- [ ] **T07**: Verify document generation trigger — confirm a route (e.g., `POST /api/bezwaar/besluit/{id}/publish`) or service method that, on transition to `published`, generates a Word/PDF decision document from a template and stores it as `decisionDocument`. If only a manual upload path exists, file an issue titled "Add template-driven decision document generation (REQ-BD-9)".

- [ ] **T08**: Verify publication + notification flow — publishing a bezwaarBesluit MUST: (a) set `publishedAt`, (b) emit notifications to bezwaarmaker, gemachtigde (if any), primair beslisser, and advisory committee secretaris (if `advisoryReport` set), (c) record those recipients in `notifiedRecipients`, (d) transition the case status to "Beslissing op bezwaar". If a MijnOverheid Berichtenbox integration is configured, the decision SHOULD be filed there as well. Record gaps as follow-up issues.

## Doc & test polish

- [ ] **T09**: Add a "Bezwaar decision outcome matrix" diagram to `docs/ARCHITECTURE.md` (or the closest existing architecture doc) reflecting the five canonical Awb 7:11 dispositions, their replacement-besluit rules, and their proceskosten implications. Cross-link to this change and to `bezwaar-lifecycle`, `bezwaar-hearing`, `bezwaar-advisory-committee`, `beroep-escalation`.

- [ ] **T10**: Cross-reference check — open `openspec/changes/bezwaar-lifecycle`, `openspec/changes/bezwaar-hearing`, `openspec/changes/bezwaar-advisory-committee`, `openspec/changes/beroep-escalation` (only ones that exist as active changes), and add a "See also: `bezwaar-decision`" line in each proposal's `Cross-Project Dependencies` section where missing. Skip archived directories.

## Pre-commit verification

- [ ] **V01**: `openspec validate bezwaar-decision --type change --strict` → exit code 0
- [ ] **V02**: `openspec change show bezwaar-decision --json --deltas-only | jq '.deltaCount'` → ≥ 10 (one delta per REQ-BD-1..10)
- [ ] **V03**: Every REQ-BD-* in `specs/bezwaar-decision/spec.md` carries at least one `#### Scenario:` block
- [ ] **V04**: No code files modified outside `openspec/changes/bezwaar-decision/` and the optional `docs/ARCHITECTURE.md` update from T09 (verify with `git diff --name-only origin/development...HEAD`)
