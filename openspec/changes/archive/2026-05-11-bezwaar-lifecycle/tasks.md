# Tasks: bezwaar-lifecycle

This is a **GENERATE-style** change: the schemas, seed data, and frontend components for bezwaar already exist via the archived `bezwaar-beroep-workflow` change (2026-03-22). Tasks here verify that the live code matches the formalized capability spec. No code changes are introduced; any gap found is filed as a follow-up issue rather than fixed inside this change.

## Schema and seed verification

- [ ] **T01**: Confirm `case` schema and `caseType` seed in `lib/Settings/procest_register.json` + `lib/Settings/bezwaar_seed_data.json` declare — at minimum — a `caseType` titled "Bezwaar" with `processingDeadline = P6W`, `extensionAllowed = true`, `extensionPeriod = P6W`, `suspensionAllowed = true`, `origin = external`. Confirm `x-openregister-calculations` (ADR-022) declares deadline formulas for `ontvangstbevestigingDeadline`, `afhandelDeadline`, and `dwangsomStartDate` with the inputs listed in `design.md`. Record any drift; file an issue if found.

- [ ] **T02**: Verify status-type seed — `bezwaar_seed_data.json` declares 10 `statusType` records linked to the Bezwaar `caseType`: 8 ordered (`Ontvangen` 1, `Ontvankelijkheidstoets` 2, `In behandeling` 3, `Hoorzitting gepland` 4, `Hoorzitting afgerond` 5, `Advies uitgebracht` 6, `Beslissing op bezwaar` 7, `Afgehandeld` 8) + 2 terminal (`Niet-ontvankelijk`, `Ingetrokken`). Each status references the Awb article from the spec's table.

- [ ] **T03**: Verify role-type seed — 7 `roleType` records linked to the Bezwaar `caseType`: `Bezwaarmaker`, `Behandelaar bezwaar`, `Voorzitter commissie`, `Lid commissie`, `Secretaris commissie`, `Vertegenwoordiger`, `Primair beslisser`. Each maps to a generic role (initiator, handler, decision_maker, advisor, coordinator, stakeholder, advisor).

## State machine and guards

- [ ] **T04**: Verify state-machine guards — load the case controller transition handler and assert: (a) `Ontvankelijkheidstoets → Niet-ontvankelijk` requires a non-empty `timelinessAssessment` or `vormvereisteReason` on the linked `objection`; (b) `In behandeling → Advies uitgebracht | Beslissing op bezwaar` is only allowed when `objection.hearingWaived = true` with a non-empty `waiverReason` OR when `hearingSession.status = uitgevoerd`; (c) backward transitions to non-terminal statuses are rejected. Use the OpenRegister state-machine plumbing — no procest-specific transition service.

- [ ] **T05**: Verify deadline scheduler — confirm OpenRegister's `RenderObject::renderCalculations` populates `ontvangstbevestigingDeadline`, `afhandelDeadline`, `dwangsomStartDate` on save. Confirm verdaging recomputation: setting `verdaagdOp` to a date recomputes `afhandelDeadline = origDeadline + P6W` and appends an audit entry with `awbReference = "Awb 7:10 lid 3"`. Confirm opschorting: `opschortingStart` stops the clock; `opschortingEnd` adds the elapsed delta to `afhandelDeadline` and writes an audit entry referencing `Awb 7:10 lid 4`.

## Controller and view verification

- [ ] **T06**: Verify controller endpoints — `appinfo/routes.php` exposes `GET /api/cases/{id}/lifecycle` returning current status, deadlines, and allowed transitions; `POST /api/cases/{id}/transition` accepting `{ targetStatus, reason, awbReference }`. Confirm both routes are bound to the case controller and authorized via `case-sharing-collaboration` rules.

- [ ] **T07**: Verify Vue lifecycle view — `src/views/Cases/BezwaarLifecycle.vue` (or equivalent) renders: a status timeline using the 10 status-type ordering, deadline countdown badges (green/yellow/red per the 5-workday rule from REQ-BL-10), a transition action menu that hides disallowed targets, and a verdaging/opschorting modal. Record component names; file an issue if a sub-component is missing.

- [ ] **T08**: Verify ingebrekestelling & dwangsom UI — when `ingebrekestellingDate` is set on the case, the lifecycle view SHALL show a red banner with the dwangsom counter (`dwangsomAccrued`), the 14-day grace clock, and the running total liability up to € 1 442.

## Audit and integration verification

- [ ] **T09**: Verify audit trail integration — every status transition writes an OpenRegister audit entry containing `actor`, `fromStatus`, `toStatus`, `reason`, `awbReference`, `timestamp`. Confirm transitions that change legal posture (Ontvankelijkheidstoets outcome, verdaging, opschorting, hoorrecht-afzien, intrekking) reject empty `awbReference` with HTTP 422.

- [ ] **T10**: Verify sister-capability hooks — confirm: (a) `bezwaar-advisory-committee` writing an `advisoryReport` triggers transition to `Advies uitgebracht`; (b) `bezwaar-hearing` setting `hearingSession.status = uitgevoerd` triggers transition to `Hoorzitting afgerond`; (c) `bezwaar-decision` recording a `decision` with `dispositionType = niet_ontvankelijk` short-circuits to `Niet-ontvankelijk`. Each hook is implemented via OR object-event listeners — record handler class names; file an issue per missing hook.

## Pre-commit verification

- [ ] **V01**: `openspec validate bezwaar-lifecycle --type change --strict` → exit code 0
- [ ] **V02**: `openspec change show bezwaar-lifecycle --json --deltas-only | jq '.deltaCount'` → ≥ 10 (one delta per REQ-BL-1..10)
- [ ] **V03**: Every REQ-BL-* in `specs/bezwaar-lifecycle/spec.md` carries at least one `#### Scenario:` block
- [ ] **V04**: No code files modified outside `openspec/changes/bezwaar-lifecycle/` (verify with `git diff --name-only origin/development...HEAD`)
