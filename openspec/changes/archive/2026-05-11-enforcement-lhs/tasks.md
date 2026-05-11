# Tasks: enforcement-lhs

This change is **spec-only**; no source code is modified by it. Tasks T01–T05 capture the implementation work that the follow-up apply change will execute; verification tasks V01–V04 gate the spec itself.

## Schema and data

- [ ] **T01**: Add `lhsMatrix` schema to `lib/Settings/procest_register.json` (OpenAPI 3.0 form): properties `name`, `version` (integer), `active` (boolean), `ernstAxis`, `gedragAxis`, `actorTypeAxis` (each `array<string>`), `cells` (array of `{ernst, gedrag, actorType, interventie, note}`), `auditTrail` (array). Add `sanctionRecommendation` schema with: `case` (UUID), `ernst`, `gedrag`, `actorType`, `matrixVersion` (integer), `recommendedInterventie`, `appliedInterventie`, `override` (boolean), `overrideJustification` (string ≥ 20 chars when `override=true`), `recommendedBy` (NC UID). Required lists: `lhsMatrix`: `[name, version, active, ernstAxis, gedragAxis, actorTypeAxis, cells]`; `sanctionRecommendation`: `[case, ernst, gedrag, actorType, matrixVersion, recommendedInterventie, recommendedBy]`.

- [ ] **T02**: Add default national LHS data import to the VTH seed step. The seed SHALL create one `lhsMatrix` with `version = 1`, `active = true`, the three axes filled per `design.md`, and 48 `cells` matching the IPO/VNG reference matrix (per-actor-type variants documented in `design.md` and the appended cell table in `lib/Settings/seed/lhs-matrix-2024.json`). Idempotent: re-running the seed MUST NOT create duplicate matrices.

## Backend service

- [ ] **T03**: Implement `SanctionRecommendationService` in `lib/Service/SanctionRecommendationService.php`. Public methods: `recommend(string $caseId, string $ernst, string $gedrag, string $actorType): sanctionRecommendation`, `override(string $recommendationId, string $appliedInterventie, string $justification): sanctionRecommendation`. Implementation rules: load the `active = true` matrix, map cells into an in-memory dictionary keyed `"ernst:gedrag:actorType"`, derive user UID from `IUserSession`, persist via OpenRegister object service, reject `override` calls where `justification` is shorter than 20 characters or the requested interventie is *more* severe than the recommended one unless the caller has the manager role.

## Frontend

- [ ] **T04**: Update the enforcement wizard step 1 (`src/components/enforcement/EnforcementWizardStep1.vue` or the equivalent existing component) to call `SanctionRecommendationService` via the OpenRegister-backed API endpoint, render the three axis selectors, render the three-panel matrix preview, and show the recommendation card. Wire the override toggle to display the justification field and to disable harsher options for non-managers. Add an admin grid editor under VTH Settings → Handhavingsstrategie that previews all three actor-type panels and saves edits as a new matrix version (active flag transferred).

## Audit & integration

- [ ] **T05**: Wire recommendation and override events into the existing `vth-module` enforcement workflow audit trail. Append an `lhs_recommendation` event to the case timeline whenever `recommend()` or `override()` is called, with payload `{ recommendationId, ernst, gedrag, actorType, recommended, applied, override }`. Confirm the existing per-save OpenRegister audit log also captures the `sanctionRecommendation` mutation.

## Verification (gate this change)

- [ ] **V01**: `openspec validate enforcement-lhs --type change --strict` → exit code 0
- [ ] **V02**: `openspec change show enforcement-lhs --json --deltas-only | jq '.deltaCount'` → ≥ 8 (one delta per REQ-LHS-1..8)
- [ ] **V03**: Every REQ-LHS-* in `specs/enforcement-lhs/spec.md` carries at least one `#### Scenario:` block; every Scenario uses Given/When/Then keywords
- [ ] **V04**: No code files modified outside `openspec/changes/enforcement-lhs/` — verify with `git diff --name-only origin/development...HEAD | grep -v '^openspec/changes/enforcement-lhs/'` returns empty
