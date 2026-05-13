# Tasks: voorstel-management

This is a **GENERATE-style** change: the implementation already exists (PRs #331 parafeerroute-engine, #332 parafering-actions). Tasks here verify that the live code matches the formalized capability spec. No code changes are introduced; any gap found is filed as a follow-up issue rather than fixed inside this change.

## Schema documentation

- [ ] **T01**: Confirm `voorstel` schema in `lib/Settings/procest_register.json` declares — at minimum — properties `case`, `type`, `onderwerp`, `steller`, `afdeling`, `portefeuillehouder`, `status`, `parafeerroute`, `routeSnapshot`, `currentStep`, `returnedFromStep`, `document`, `bijlagen`, `behandeling`; required list contains `case`, `type`, `onderwerp`, `steller`, `status`; `type` enum is exactly `[dt_advies, collegeadvies, raadsvoorstel]`; `status` enum is exactly `[concept, in_parafering, ter_accordering, geaccordeerd, aangeboden, besloten, gearchiveerd, teruggestuurd]`. Record any drift in this task block and open a follow-up issue if found.

## Capability verification

- [ ] **T02**: Verify lifecycle — load each `STATUS_*` constant referenced in `lib/Service/ParafeerRouteService.php` and `lib/Service/ParafeerActieService.php`; assert every constant value is one of the eight statuses listed in T01. If a status constant exists in code but not in the schema enum (or vice versa), record + file issue.

- [ ] **T03**: Verify create-from-case path — `POST /api/parafering/voorstellen` (route `parafering#createVoorstel` in `appinfo/routes.php`) accepts a case ID and produces a voorstel with `status = concept`. Confirm onderwerp/afdeling/portefeuillehouder defaults are applied when the case carries those fields. Record current behavior; file issue if a default field is missing.

- [ ] **T04**: Verify route snapshotting — call `ParafeerRouteService::startParafering($voorstelId)` against a voorstel with `parafeerroute` set and confirm: `routeSnapshot` is populated with the linked route's `steps[]` as a JSON string, `currentStep` becomes `1`, `status` becomes `in_parafering`. Verify that later edits to the source parafeerroute do NOT mutate `routeSnapshot` of in-flight voorstellen.

- [ ] **T05**: Verify multiple-voorstellen-per-case — create two voorstellen on the same case (one `dt_advies`, one `collegeadvies`), advance one to `in_parafering`, leave the other as `concept`, assert their statuses remain independent and that the case detail view lists both.

## Doc & test polish

- [ ] **T06**: Add a "Voorstel lifecycle" diagram (text/ASCII or Mermaid) to `docs/ARCHITECTURE.md` (or the closest existing architecture doc) reflecting the eight statuses and allowed transitions from `design.md`. Cross-link to this change and to `parafeerroute-engine` / `parafering-actions`.

- [ ] **T07**: Cross-reference check — open `openspec/changes/bw-parafering`, `openspec/changes/parafeerroute-engine`, `openspec/changes/parafering-actions`, `openspec/changes/besluitvorming-workflow`, and add a "See also: `voorstel-management`" line in their proposal.md `Cross-Project Dependencies` sections (only the ones that currently lack the link). Skip changes already archived (do not modify archived directories).

- [ ] **T08**: Ensure PHPUnit coverage for the lifecycle — confirm there is at least one test in `tests/Unit/Service/` exercising each of the four critical transitions: `concept → in_parafering`, `in_parafering → in_parafering` (step advance), `in_parafering → teruggestuurd`, and `final-step → geaccordeerd`. If any transition lacks a test, file an issue against the relevant service test file rather than authoring the test in this change.

- [ ] **T09**: Run `openspec validate voorstel-management --type change --strict` from the procest repo root and confirm it passes. Re-run `openspec list` and confirm the change appears with task progress.

## Pre-commit verification

- [ ] **V01**: `openspec validate voorstel-management --type change --strict` → exit code 0
- [ ] **V02**: `openspec change show voorstel-management --json --deltas-only | jq '.deltaCount'` → ≥ 8 (one delta per REQ-VM-1..8)
- [ ] **V03**: Every REQ-VM-* in `specs/voorstel-management/spec.md` carries at least one `#### Scenario:` block (grep: `### Requirement:` count == `#### Scenario:` count is NOT required, but each Requirement section MUST contain at least one Scenario subsection)
- [ ] **V04**: No code files modified outside `openspec/changes/voorstel-management/` and the optional `docs/ARCHITECTURE.md` update from T06 (verify with `git diff --name-only origin/development...HEAD`)
