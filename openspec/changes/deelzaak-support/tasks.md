# Tasks: deelzaak-support

## Implementation Tasks

- [x] **T01**: Create `DeelzaakService` with `createDeelzaak()`, `getHierarchy()`, `validateClosureAllowed()`, and `createVervolgzaak()` methods
- [x] **T02**: Create `DeelzaakController` with REST endpoints for create, hierarchy, and closure-check
- [x] **T03**: Register `DeelzaakController` routes in `appinfo/routes.php`
- [x] **T04**: Create `DeelzaakHierarchyTree.vue` — recursive tree component showing case hierarchy with status badges (min 3 levels)
- [x] **T05**: Create `CreateDeelzaakDialog.vue` — modal dialog for manual deelzaak creation with type selector restricted to `subCaseTypes`
- [x] **T06**: Create `DeelzaakTypenTab.vue` — settings tab for configuring `subCaseTypes` on a caseType
- [x] **T07**: Wire `DeelzaakHierarchyTree` and `CreateDeelzaakDialog` into `CaseDetail.vue`
- [x] **T08**: Add `DeelzaakTypenTab` to `CaseTypeDetail.vue`

## Verification Tasks

- [x] **V01**: Deelzaak inherits `deadline` and `archiveNomination` from parent when created
- [x] **V02**: Only `subCaseTypes` configured on the caseType are shown in the create dialog
- [x] **V03**: Hierarchy tree shows at least 3 levels of nesting with correct status badges
- [x] **V04**: Closure guard blocks parent case closure when deelzaken are still open
- [x] **V05**: `createVervolgzaak()` creates a new case with `aardRelatie=vervolg` linking back to the original
- [x] **V06**: PHPUnit tests cover createDeelzaak, validateClosureAllowed, and createVervolgzaak (min 3 methods each)
