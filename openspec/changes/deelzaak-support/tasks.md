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

- [ ] **T01**: Add `fetchSubCases(parentCaseUuid)` action to `src/store/modules/caseStore.js` — queries OpenRegister with `?parentCase={uuid}` filter and stores results in `state.subCases`.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T01`

- [ ] **T02**: Add `fetchParentCase(parentCaseUuid)` action to `src/store/modules/caseStore.js` — fetches parent case object and stores in `state.parentCase`.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T02`

- [ ] **T03**: Add `fetchSubCaseCounts(caseUuidArray)` action — single batch query for sub-case counts per page, returning a `{[parentUuid]: count}` map. Used by CaseList for badge rendering.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T03`

- [ ] **T04**: Add `state.subCases`, `state.parentCase`, `state.subCaseCounts` to the case store with appropriate getters: `getSubCases`, `getParentCase`, `getSubCaseCount(uuid)`.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T04`

## 2. SubCasesSection Component

- [ ] **T05**: Create `src/views/cases/components/SubCasesSection.vue`.
  - Section header shows "Deelzaken (X/Y voltooid)" roll-up computed from `subCases` where `endDate != null`.
  - Compact table with columns: title (router-link to sub-case detail), status badge, behandelaar, deadline.
  - Empty state: "Nog geen deelzaken aangemaakt" when sub-cases array is empty but caseType has `subCaseTypes`.
  - "Deelzaak aanmaken" button hidden when: `parentCase != null` (current is sub-case), `endDate != null` (closed), or `subCaseTypes.length === 0`.
  - On mount: calls `fetchSubCases(caseUuid)`.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T05`

## 3. Parent Case Breadcrumb

- [ ] **T06**: Add parent case breadcrumb to `src/views/cases/CaseDetail.vue`.
  - On case load: if `case.parentCase` is non-null, call `fetchParentCase(case.parentCase)`.
  - Render breadcrumb above case title: `<router-link :to="parentCaseRoute">{{ parentCase.title }}</router-link> › {{ case.title }}`.
  - Do not render breadcrumb when `case.parentCase` is null.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T06`

- [ ] **T07**: Integrate `SubCasesSection` into `src/views/cases/CaseDetail.vue`.
  - Import and register the component.
  - Render in case detail only when `caseType.subCaseTypes` is non-empty.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T07`

## 4. Sub-case Creation via CaseCreateDialog

- [ ] **T08**: Extend `src/views/cases/CaseCreateDialog.vue` to accept `parentCase` (UUID) and `parentCaseType` props.
  - When `parentCase` prop is provided: change dialog title to `t(appName, 'Deelzaak aanmaken')`.
  - Filter the caseType dropdown to only show types listed in `parentCaseType.subCaseTypes`.
  - Show parent case title as read-only context at the top of the form.
  - On submit: include `parentCase` UUID in the case payload.
  - `@spec openspec/changes/deelzaak-support/tasks.md#T08`

- [ ] **T09**: Wire the "Deelzaak aanmaken" button in `SubCasesSection.vue` to open `CaseCreateDialog` with `parentCase` and `parentCaseType` props.
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
