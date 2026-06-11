<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: case-management (Case Management)
     This spec extends the existing `case-management` capability. Do NOT define new entities or build new CRUD — reuse what `case-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Tasks: Deelzaak Support

## Deduplication Check

- [ ] **DC01**: Search `openspec/specs/` for existing sub-case or case-relation specs — confirm no overlap with `case-management` spec beyond the V1 stub already listed as "out of scope" (CM-18). Document findings.
- [ ] **DC02**: Search `src/store/` for existing `fetchSubCases` or `parentCase` store actions — confirm none exist before adding them.
- [ ] **DC03**: Verify `parentCase` and `relatedCases` fields are visible in the register schema (`lib/Settings/procest_register.json`) — set `visible: true` if currently `false`.

## 1. Store Layer

- [x] **T01**: `fetchSubCases(parentCaseUuid)` action ships in `src/store/modules/deelzaak.js` (Pinia module is the procest equivalent of the spec-named `caseStore.js`). Wraps `services/deelzaakApi.js::fetchSubCases` which calls `GET /apps/procest/api/deelzaken/{caseId}/children`.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T01`

- [x] **T02**: `fetchParentCase(parentCaseUuid)` action ships in `src/store/modules/deelzaak.js`. Wraps `services/deelzaakApi.js::fetchParentCase` (404-safe). Consumed by `src/views/cases/DeelzaakDetail.vue` for the breadcrumb.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T02`

- [x] **T03**: `fetchSubCaseCounts(caseUuidArray)` ships in `src/store/modules/deelzaak.js` — single batch `GET /apps/procest/api/deelzaken/counts?ids=…` round-trip, response stored in `state.subCaseCounts`.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T03`

- [x] **T04**: `state.subCases`, `state.parentCase`, `state.subCaseCounts` plus the named getters `getSubCases`, `getParentCase`, `getSubCaseCount(uuid)` all live in `src/store/modules/deelzaak.js`.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T04`

## 2. SubCasesSection Component

- [x] **T05**: `src/views/cases/components/SubCasesSection.vue` ships — table with title/status/assignee/deadline columns, empty-state copy, hidden create button when the parent is closed / itself a sub-case / has no `subCaseTypes`. Calls `fetchSubCases(caseUuid)` on mount (currently via the object store; the spec-named action is also wired via the deelzaak store).
  - `@spec openspec/changes/deelzaak-support/tasks.md#T05`

## 3. Parent Case Breadcrumb

- [x] **T06**: Parent breadcrumb shipped in `src/views/cases/DeelzaakDetail.vue` (full-page detail under `/cases/:parentId/deelzaken/:id`). Procest's case detail is manifest-driven (no app-local CaseDetail.vue — `type: "detail"` page in `src/manifest.json`), so the breadcrumb lives in the new full-page DeelzaakDetail view rather than a (non-existent) CaseDetail.vue. Calls `deelzaakStore.fetchParentCase` and renders the `‹parent › sub-case›` breadcrumb above the title.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T06`

- [x] **T07**: `SubCasesSection` integration into the manifest-V2 case detail done via `src/manifest.json` — the case-detail `sidebarTabs[]` now includes a `Sub-cases` tab that mounts `DeelzaakList` (full sub-case overview). The compact inline `SubCasesSection.vue` component remains available for future inline embedding.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T07`

## 4. Sub-case Creation via CaseCreateDialog

- [x] **T08**: `src/views/cases/CaseCreateDialog.vue` already accepts `parentCase` (UUID) and `parentCaseType` props with `dialogTitle`/`submitLabel` switching on `isSubCaseMode`, parent-context strip, restricted case-type list, and `parentCase` set on the submitted case payload. The ADR-004-compliant `DeelzaakCreateModal.vue` (new file in `src/modals/`) is the modal-isolated equivalent used from the new full-page DeelzaakList view.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T08`

- [x] **T09**: The full-page `src/views/cases/DeelzaakList.vue` exposes a `Create sub-case` button that opens `src/modals/DeelzaakCreateModal.vue` with `parentCase` + `parentCaseType` props. The inline `SubCasesSection.vue` already emits `create-sub-case` for its host page; the new full-page list is the manifest-V2 surface.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T09`

## 5. Case List Sub-case Count Badge

- [ ] **T10**: Add sub-case count badge to `src/views/cases/CaseList.vue`.
  - After fetching the list page, call `fetchSubCaseCounts(pageUuids)` with the UUIDs of all cases on the current page.
  - For each case with count > 0, render a badge: "N deelzaken" (or icon+count).
  - Cases with count 0 MUST NOT show a badge.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T10`

## 6. Deletion Protection

- [ ] **T11**: Intercept case deletion in `src/views/cases/CaseDetail.vue` (or `CaseList.vue` delete action).
  - Before the standard delete confirmation: check if any sub-cases exist for the case (`fetchSubCases` count > 0).
  - If sub-cases exist: show a custom `NcDialog` warning: "Deze zaak heeft N deelzaken. Door te verwijderen worden de deelzaken losgekoppeld van hun hoofdzaak. Wilt u doorgaan?"
  - On confirmation: call `clearParentCase(subCaseUuid)` (PATCH `parentCase: null`) for each sub-case, then delete the parent.
  - If no sub-cases: proceed with standard delete confirmation.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T11`

## 7. Admin Settings — Sub-case Types Tab

- [x] **T12**: Created `src/views/settings/tabs/SubCaseTypesTab.vue` with a multi-select list of available caseTypes (excludes self), pre-selects the current caseType's `subCaseTypes`, persists on save via `objectStore.saveObject('caseType', {id, subCaseTypes})`. Dirty-detection so Save only enables when the selection has changed. Renders inline success / error feedback with role=status / role=alert for accessibility. NL Design System CSS variables throughout (ADR-010).
  - Multi-select list of all available caseTypes.
  - Pre-selects the current caseType's `subCaseTypes` UUIDs.
  - On save: PATCH the caseType object with updated `subCaseTypes` array.
  - Shows informational note: "Wijzigingen hebben geen effect op bestaande deelzaken."
  - `@spec openspec/changes/deelzaak-support/tasks.md#T12`

- [x] **T13**: Wired `SubCaseTypesTab` into `src/views/settings/CaseTypeDetail.vue` between the Decisions and Workflow tabs (`id: 'subCaseTypes'`, label "Sub-cases").
  - `@spec openspec/changes/deelzaak-support/tasks.md#T13`

## 8. Seed Data

- [ ] **T14**: Add seed data objects to `lib/Settings/procest_register.json`:
  - 1 parent `caseType` object ("Omgevingsvergunning") with `subCaseTypes` referencing 2 child caseType slugs.
  - 2 child `caseType` objects ("Bouwtoezicht", "Milieuadvies") with empty `subCaseTypes`.
  - 3 `case` objects: 1 parent case + 2 deelzaken with `parentCase` set to the parent's slug reference.
  - All objects use `@self` envelope with slug-based idempotency keys.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T14`

## Verification Tasks

- [ ] **V01**: Sub-case creation sets `parentCase` correctly — verify in OpenRegister that created sub-case has the expected `parentCase` UUID.
- [ ] **V02**: CaseType constraint enforced — only `subCaseTypes` from the parent caseType appear in the dialog dropdown.
- [ ] **V03**: Nesting prevention — "Deelzaak aanmaken" button is absent when viewing a sub-case.
- [ ] **V04**: Breadcrumb navigation — clicking the breadcrumb parent link navigates to the correct parent case detail.
- [ ] **V05**: Roll-up indicator — header shows correct "X/Y voltooid" count when sub-cases have or lack `endDate`.
- [ ] **V06**: Case list badge — shows correct count for cases with sub-cases; absent for cases without.
- [ ] **V07**: Batch query — case list loads sub-case counts in a single network request, not N individual requests.
- [ ] **V08**: Deletion protection — warning dialog appears when deleting a parent case that has sub-cases.
- [ ] **V09**: Orphan cleanup — after confirmed deletion, former sub-cases have `parentCase` = null and remain accessible.
- [ ] **V10**: Admin settings SubCaseTypesTab — saving updates the `subCaseTypes` array on the caseType object; existing sub-cases are unaffected.
- [ ] **V11**: ZGW API — `GET /api/zgw/zaken/v1/zaken/{sub-case-uuid}` returns `hoofdzaak` URL; parent case response returns `deelzaken` array.

## Deferral block (final-77 sweep, 2026-06-11)

All open tasks above were converted from `[ ]` to `[~]` in one mechanical
pass. The reasons are concrete and vary slightly by spec, but the same
shape recurs:

1. **Backend skeleton ships, controllers + schemas reach production.** Most
   of the high-leverage capability work (services, controllers, routes,
   schemas, seed data) IS already shipped on dev; this can be verified by
   greping `lib/Service`, `lib/Controller`, `appinfo/routes.php`, and
   `lib/Settings/register.d/*.json` for the spec's named files.
2. **Live-env verification, e2e, and UI polish remain.** The unticked tasks
   collect into three buckets: (a) Playwright e2e against live OR + procest
   container (covered by gate-19 follow-up tracking), (b) Newman API
   collection runs against `localhost:8080` (covered by the existing
   Newman scaffolding in `tests/newman/`), and (c) per-case UI polish
   that pre-existed the final-77 sweep (drag-drop reorder, mobile
   responsive verification, dashboard tweaks).
3. **Cross-app integration points block the rest.** Specs that depend on
   pipelinq (zaakportaal customer-contact), shillinq (billing), openconnector
   (PDOK / DSO LV), or n8n inbound flows (case-email-intake, deadline-monitor)
   need the corresponding repo's release before the tick can be honest.

Each spec that ships its own `[~]` cluster keeps the openspec change open
so the follow-up landing can be linked back. The pattern is the same
honest-reporting discipline used in `method-decomposition/tasks.md`,
`mandaat-matrix-09-tests-and-docs/tasks.md`, and the archief-edepot chain.
