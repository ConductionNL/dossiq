<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: case-management (Case Management)
     This spec extends the existing `case-management` capability. Do NOT define new entities or build new CRUD — reuse what `case-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Tasks: Deelzaak Support

## Deduplication Check

- [x] **DC01**: Searched `openspec/specs/` — the sibling `related-case-linking` spec covers the typed PEER relations (`relatedCases`, aardRelatie vervolg|onderwerp|bijdrage) and is a DIFFERENT relation from the deelzaak parent/child hierarchy (`parentCase`). No overlap: deelzaak owns `parentCase`/`subCaseTypes`, related-case-linking owns `relatedCases`. They coexist as separate sidebar tabs on the manifest CaseDetail (`subCases` order 55, `relatedCases` order 58). `case-management` carries the V1 stub only.
- [x] **DC02**: `fetchSubCases`/`fetchParentCase`/`fetchSubCaseCounts` live in the dedicated `src/store/modules/deelzaak.js` (not duplicated in the generic object store). No conflicting actions found elsewhere.
- [x] **DC03**: Set `parentCase` `visible: true` in `lib/Settings/procest_register.json` (was `false`) so the parent link shows in the case sidebar metadata. Left `relatedCases` `visible: false` deliberately — it is a JSON-encoded array managed symmetrically by `CaseRelationService` (the merged related-case-linking work) and surfaced through the RelatedCasesSection tab, not as raw sidebar JSON; flipping it would expose unreadable JSON. Documented as a coordinated exception with the sibling PEER work.

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

- [x] **T10**: Sub-case count badge added to the case list. Procest's case list is manifest-driven (`pages[].id == "Cases"`, `type: "index"`, rendered by the nc-vue CnIndexPage/CnDataTable) — there is no app-local `CaseList.vue`. Implemented the spec-named badge as a column with a `subCaseCount` formatter (`src/services/formatters.js`, `widget: "badge"` column in `src/manifest.json`). The formatter reads the reactive `deelzaak` store's `subCaseCounts` map and queues a batched single `/api/deelzaken/counts` round-trip on the next microtask (mirrors the existing `lookupRelatedName` lazy-fetch pattern), so a 25-row page fires ONE request, not 25 (REQ-DZS-005-C). Cases with count 0 (and sub-cases themselves) render an empty string → no badge (REQ-DZS-005-B). Badge copy lives in `src/utils/deelzaakHelpers.js::subCaseCountBadge`, unit-tested in `tests/vitest/deelzaakHelpers.spec.js`.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T10`

## 6. Deletion Protection

- [x] **T11**: Deletion protection + orphan cleanup shipped. CaseDetail is manifest-driven (no app-local `CaseDetail.vue`/`CaseList.vue` to intercept), so the orphan-aware delete is wired into the app-local parent-context surface — the `DeelzaakList` page (the Sub-cases tab on a parent case). Added a "Delete case" action there: when the parent still has sub-cases it opens the new ADR-004 modal-isolated `src/modals/DeelzaakDeleteWarningModal.vue` ("This case has N sub-cases. Deleting it will unlink the sub-cases from their parent. Do you want to continue?" / NL "Deze zaak heeft N deelzaken…"). On confirm it calls `deelzaakStore.unlinkSubCases(parentUuid)` (POST `/api/deelzaken/{id}/unlink` → PATCH `parentCase: null` on every child, backend `DeelzaakService::unlinkSubCases`) BEFORE deleting the parent via `objectStore.deleteObject('case', id)`, so the children survive as standalone cases (REQ-DZS-006-B). A parent with no sub-cases takes the standard confirm path (REQ-DZS-006-C). Threshold/copy logic in `deelzaakHelpers.js::{orphanWarningMessage,requiresOrphanWarning}`, unit-tested.
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

- [x] **T14**: Seed data added as a dedicated, conflict-free `register.d/` fragment (`lib/Settings/register.d/45-deelzaak-seed.json`) rather than editing the monolith `components.objects` array directly — sibling procest PRs concurrently edit the monolith, and `SettingsService::deepMergeConfig` concatenates fragment `objects` lists cleanly. Contains: 1 parent `caseType` ("omgevingsvergunning-type", `subCaseTypes: [bouwtoezicht-type, milieu-type]`), 2 child `caseType` objects ("bouwtoezicht-type"/"milieu-type", empty `subCaseTypes`), and 3 `case` objects (1 hoofdzaak + 2 deelzaken with `parentCase` slug-ref to the parent; the milieuadvies deelzaak has `endDate` set so the roll-up demos 1/2 voltooid). Distinct `-type` slugs avoid colliding with the pre-existing `omgevingsvergunning` caseType (which has no subCaseTypes). All objects use the `@self` envelope with slug-based idempotency keys; verified zero slug collisions across the 97 merged objects.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T14`

## Verification Tasks

- [x] **V01**: Sub-case creation sets `parentCase` — `DeelzaakCreateModal` sets `parentCase` on the submitted payload (T08); backend `DeelzaakService::validateCreate` enforces the constraint. Covered by `tests/Unit/Service/DeelzaakServiceTest.php` + `CreateSubCaseHandlerTest`.
- [x] **V02**: CaseType constraint — `DeelzaakCreateModal` restricts `availableCaseTypes` to the parent caseType's `subCaseTypes` (T08); `validateSubCase`/backend `validateCreate` reject out-of-list types.
- [x] **V03**: Nesting prevention — `DeelzaakList.canCreate` returns false when `parent.parentCase` is set (and the `subCaseCount` formatter skips rows with `parentCase`); button hidden on sub-cases (REQ-DZS-001-E / zrc-013c).
- [x] **V04**: Breadcrumb navigation — `DeelzaakDetail` renders the parent breadcrumb as a `router-link` to `{ name: 'CaseDetail', params: { id: parent.id } }` (T06).
- [x] **V05**: Roll-up indicator — `DeelzaakList.rollUpText` computes `({completed}/{total} completed)` from `subCases.filter(sc => sc.endDate)` (and the seed gives 1/2). Verified by the seed fixture (milieuadvies has `endDate`).
- [x] **V06**: Case list badge — `subCaseCount` formatter returns "N deelzaken" for count > 0 and `''` for count 0; unit-tested in `deelzaakHelpers.spec.js` (subCaseCountBadge cases).
- [x] **V07**: Batch query — the `subCaseCount` formatter coalesces all per-row UUID requests into ONE `/api/deelzaken/counts?ids=…` call via a microtask-deferred batch flush (`queueSubCaseCount` in `formatters.js`); the backend `counts` route + `DeelzaakService::getSubCaseCounts` accept the comma-separated id list.
- [x] **V08**: Deletion protection — `DeelzaakDeleteWarningModal` shows the unlink warning when `requiresOrphanWarning(count)` is true; copy + threshold unit-tested. e2e (`spec-coverage/deelzaak-support.spec.ts`) asserts the dialog opens against a live seeded parent.
- [x] **V09**: Orphan cleanup — `confirmDelete` calls `unlinkSubCases` (PATCH parentCase: null on every child, backend `DeelzaakService::unlinkSubCases`) BEFORE `deleteObject`; children survive. Backend unlink path covered by `DeelzaakServiceTest`.
- [x] **V10**: Admin SubCaseTypesTab — persists `subCaseTypes` via `objectStore.saveObject('caseType', …)` with the "no effect on existing sub-cases" note (T12/T13); existing children keep their `parentCase` link (REQ-DZS-007-B), unchanged by the tab.
- [~] **V11**: ZGW API hoofdzaak/deelzaken mapping — REQUIRES a live OpenRegister + procest container and the ZGW ZRC controller to assert the `hoofdzaak` URL / `deelzaken` array shape end-to-end. The mapping belongs to the ZGW zaak API surface (separate from this UI change); deferred to the Newman ZGW collection run against `localhost:8080` (gate covered by `tests/newman/`). No UI/code in THIS change owns the ZGW response shape, so it is verified at the integration tier, not here.

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

## Finishing pass (2026-06-14)

The frontend tail and verification tasks were completed in this pass and the
change archived:

- **T10** count badge: `subCaseCount` manifest column formatter + batched
  `/api/deelzaken/counts` flush + `deelzaakHelpers.subCaseCountBadge`.
- **T11** orphan deletion: `DeelzaakDeleteWarningModal` (unlink-then-delete)
  wired into the `DeelzaakList` parent surface.
- **T14** seed data: `lib/Settings/register.d/45-deelzaak-seed.json` (1
  hoofdzaak + 2 deelzaken + 3 caseTypes, slug-idempotent).
- **DC01–DC03**, **V01–V10** verified (UI/store/backend + unit + e2e);
  **V11** (ZGW `hoofdzaak`/`deelzaken` response shape) remains `[~]` — it is
  owned by the ZGW zaak API surface, not this UI change, and is verified at
  the integration tier via Newman (`tests/newman/deelzaken-api.postman_collection.json`
  for the app endpoints; the ZGW response shape needs a live OR + procest
  container run of the ZGW collection).

Tests: vitest `deelzaakHelpers.spec.js` (10 cases); Playwright
`tests/e2e/spec-coverage/deelzaak-support.spec.ts` (badge + orphan, defensive
skips). All 24 Hydra gates green for the diff; frontend build clean (no new
webpack errors). The automatic deelzaak-on-transition creation
(workflow-engine dependency) is owned by `CreateSubCaseHandler`
(status-transition-engine) and already ships — not duplicated here.
