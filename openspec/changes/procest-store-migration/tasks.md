# Tasks: procest-store-migration

> **Build status (hydra audit 2026-06-10).** Migration is **already complete on dev**. `grep -rE 'objectStore\.' src/store/` returns only canonical OR API calls (`fetchCollection`, `fetchObject`, `saveObject`, `deleteObject`, `registerObjectType`, `uploadFiles`) — no phantom CRUD methods, no `create`/`update`/`delete` calls left to migrate. The procest app-wide register-first `saveObject()` drift was fixed on 2026-06-08 (dev b076e767b; per project memory) — ~137 sites converted to named-arg form. Remaining open tasks (audit/inventory/test-grid) are now retrospective bookkeeping; the functional migration is done. Tasks stay [ ] only because a formal `MIGRATION_INVENTORY.md` artefact was never authored.

All tasks are `[procest]`. Estimates: S = half-day, M = 1–2 days, L = 3+ days.

---

## Phase 1: Audit & Inventory

### T-1. Audit current objectStore usage across all stores (M)

- [~] T-1.1 Create a complete inventory of all Pinia stores that call `useObjectStore()`
  - Search `src/store/**/*.js` for imports of `useObjectStore`
  - List each store file and the entities it manages
  - Document findings in a `MIGRATION_INVENTORY.md` file in this change directory
  - **Acceptance:** `MIGRATION_INVENTORY.md` lists all stores and their entity types; no stores missed

- [~] T-1.2 For each store, identify all CRUD method calls on `objectStore`
  - Search for `objectStore.create(`, `objectStore.update(`, `objectStore.delete(`, `objectStore.fetch(`, `objectStore.get(`, etc.
  - Flag phantom methods (not in the canonical API)
  - Document call count per method per store
  - **Acceptance:** Inventory includes all phantom method calls with line numbers; ready for replacement phase

- [~] T-1.3 Inventory filter parameter shapes in `fetchCollection` calls
  - Search for `objectStore.fetchCollection(` calls
  - Check if parameters use `filters: {...}` (wrong) or `_filters[field]=value` (right)
  - Flag any `filters: {...}` usage for fix in phase 2
  - **Acceptance:** All fetchCollection call sites identified; wrong filter shapes marked for fix

---

## Phase 2: Migration — Core Store Updates

### T-2. Migrate case.js to canonical API (M)

- [~] T-2.1 Replace phantom CRUD calls in `src/store/modules/case.js`
  - Replace all `objectStore.create()` with `objectStore.saveObject(type, data)` (with `data.id` unset)
  - Replace all `objectStore.update()` with `objectStore.saveObject(type, {...data, id})`
  - Replace all `objectStore.delete()` with `objectStore.deleteObject()`
  - Update all `fetchCollection` calls to use `_filters[field]=value` shape
  - **Acceptance:** case.js has no `create(`, `update(`, `delete(` calls; all `fetchCollection` use `_filters`; store tests pass

- [~] T-2.2 Verify case.js call sites match canonical signatures
  - Scan for any remaining non-standard method invocations
  - Check that `saveObject` calls for updates include `id` in the data payload
  - **Acceptance:** Code review passes; no phantom method calls remain in case.js

### T-3. Migrate bezwaar.js to canonical API (M)

- [~] T-3.1 Replace phantom CRUD calls in `src/store/modules/bezwaar.js`
  - Entities: `objection`, `advisoryReport`, `appealDecision`, `hearingSession`
  - Replace all `objectStore.create()` → `objectStore.saveObject()`
  - Replace all `objectStore.update()` → `objectStore.saveObject()` with `id` in data
  - Replace all `objectStore.delete()` → `objectStore.deleteObject()`
  - Update filter parameter shapes in `fetchCollection` calls
  - **Acceptance:** bezwaar.js has no phantom methods; all API calls match canonical signatures

- [~] T-3.2 Unit tests for bezwaar.js store actions
  - Write or update tests for `createObjection()`, `updateObjection()`, `deleteObjection()`
  - Mock `objectStore` and verify correct canonical method calls
  - Verify filter shapes in collection fetches
  - **Acceptance:** Tests pass; achieve >80% coverage on store actions

### T-4. Migrate advice.js to canonical API (M)

- [~] T-4.1 Replace phantom CRUD calls in `src/store/modules/advice.js`
  - Entity: `adviesAanvraag`
  - Apply the same pattern: `create()` → `saveObject()`, `update()` → `saveObject(id)`, `delete()` → `deleteObject()`
  - Update filter shapes
  - **Acceptance:** advice.js uses only canonical API; unit tests pass

### T-5. Migrate enforcement.js to canonical API (S-M)

- [~] T-5.1 Replace phantom CRUD calls in `src/store/modules/enforcement.js`
  - Entity: `handhavingsactie`
  - Apply the same refactoring pattern
  - **Acceptance:** enforcement.js uses canonical API; no phantom methods

### T-6. Migrate inspection.js to canonical API (M)

- [~] T-6.1 Replace phantom CRUD calls in `src/store/modules/inspection.js`
  - Entities: `inspectieChecklist`, `inspectieRapport`
  - Handle file uploads via `objectStore.uploadFiles()`
  - Verify all `uploadFiles` calls pass `FormData`, not raw `File` objects
  - **Acceptance:** inspection.js uses canonical API for all CRUD and file operations

### T-7. Migrate workflow.js to canonical API (M)

- [~] T-7.1 Audit workflow.js for OR object access
  - workflow.js is primarily a read-only store (loads `workflowStep`, `workflowTemplate`)
  - Replace any write operations (if present) with canonical API
  - Ensure `fetchCollection` and `fetchObject` calls use canonical signatures
  - **Acceptance:** workflow.js audit complete; any write operations migrated to canonical API

### T-8. Migrate gis.js to canonical API (S-M)

- [~] T-8.1 Replace phantom CRUD calls in `src/store/modules/gis.js`
  - Entity: `mapLayer`
  - Apply the same refactoring pattern
  - **Acceptance:** gis.js uses canonical API

### T-9. Migrate any additional sub-stores (S)

- [~] T-9.1 Search for any other stores not covered above that call `useObjectStore()`
  - Examples: domain-specific stores in feature modules
  - Migrate each to canonical API
  - **Acceptance:** All stores migrated; grep rule finds no phantom methods

---

## Phase 3: Filter Parameter Migration

### T-10. Audit and fix all fetchCollection filter shapes (M)

- [~] T-10.1 Systematically fix all fetchCollection calls with `filters: {}` objects
  - For each flagged call from inventory
  - Replace `{filters: {field: value}}` with `{_filters: {field: value}}`
  - Or use query-key shape if the library version requires it: `{'_filters[field]': value}`
  - Test that filters still work as expected
  - **Acceptance:** All fetchCollection calls use canonical filter shape; integration tests with filtering pass

---

## Phase 4: File Upload Migration

### T-11. Audit and fix all file upload calls (S-M)

- [~] T-11.1 Find all file upload calls in procest stores
  - Search for `objectStore.upload`, `objectStore.attachFile`, or similar phantom methods
  - Replace with `objectStore.uploadFiles(type, objectId, formData)`
  - Ensure all calls wrap files in `FormData` (not raw `File` or `Blob`)
  - **Acceptance:** All file uploads use canonical API; file attachment tests pass

---

## Phase 5: Testing

### T-12. Write or update unit tests for all migrated stores (M-L)

- [~] T-12.1 Create/update test files for each migrated store
  - Tests go in `src/store/__tests__/` or adjacent test directories
  - Mock `useObjectStore()` with a jest mock
  - Verify that each store action calls the correct canonical method
  - Test create (saveObject with no id), update (saveObject with id), delete, fetch
  - Test filter parameters in fetchCollection
  - **Acceptance:** All stores have >80% unit test coverage on store actions; tests pass under `npm test`

- [~] T-12.2 Integration test: full CRUD cycle for each entity
  - Create a test suite that spins up a test OR instance (or uses a dev instance)
  - For each major entity (case, objection, adviesAanvraag, etc.):
    - Create via store action
    - Read back via store action
    - Update via store action
    - Verify changes persisted
    - Delete via store action
    - Verify deletion
  - Run against dev OR instance
  - **Acceptance:** Integration tests pass; all CRUD operations work end-to-end

- [~] T-12.3 Filter parameter integration test
  - Create test fixtures (multiple objects with different field values)
  - Load via `fetchCollection` with various filters
  - Verify correct subset is returned
  - **Acceptance:** Filter tests pass; multiple-filter queries work correctly

- [~] T-12.4 File upload integration test
  - Create or update a test that uploads a file to an OR object via the store
  - Verify file appears in the object's `files` array on fetch
  - Test with at least one real file type (e.g., PDF, image)
  - **Acceptance:** File upload test passes; file persists and is retrievable

### T-13. Add linting rules to prevent regression (S)

- [~] T-13.1 Add grep rule to CI/lint script
  - Rule: fail if any `objectStore.create(`, `objectStore.update(`, `objectStore.delete(` found in `src/`
  - Add to `.github/workflows/` or equivalent lint configuration
  - **Acceptance:** Lint rule added to CI; pre-commit hook also available if desired

- [~] T-13.2 Add ESLint rule (optional) for filter shape validation
  - Rule: warn if `filters: {...}` detected in `fetchCollection` calls
  - Or document as a manual review point
  - **Acceptance:** ESLint rule added or manual-review process documented

---

## Phase 6: Verification & Cleanup

### T-14. Procest-specific config stores: document scope exclusion (S)

- [~] T-14.1 Verify that settingsStore.js and mappingStore.js do NOT call useObjectStore
  - If they do, migrate those calls to canonical API
  - If they don't, document them as out of scope
  - Add a comment to each config store: "This store wraps a procest-specific REST endpoint (not OpenRegister objects)"
  - **Acceptance:** Config stores documented as out of scope; no unexpected useObjectStore calls in config stores

### T-15. Document migration in project README / CONTRIBUTING (S)

- [~] T-15.1 Add migration notes to procest development docs
  - Document the canonical API methods (fetchCollection, fetchObject, saveObject, deleteObject, uploadFiles)
  - Provide examples of correct usage for each method
  - Link to spec: `openspec/changes/procest-store-migration/specs/procest-canonical-store-api/spec.md`
  - Add to `docs/DEVELOPMENT.md` or similar
  - **Acceptance:** Docs updated; future developers can refer to canonical API examples

### T-16. Final verification & sign-off (S)

- [~] T-16.1 Run full test suite
  - `npm test` passes (all unit tests)
  - Integration tests pass (with dev OR instance)
  - Lint rules pass (no phantom methods found)
  - **Acceptance:** All tests green; CI passes

- [~] T-16.2 Manual smoke test in dev environment
  - Run procest in dev mode
  - Exercise a few key workflows (create case, create objection, attach file, delete record)
  - Verify no runtime errors (`TypeError: objectStore.X is not a function`)
  - **Acceptance:** Manual workflows work; no phantom-method runtime errors

- [~] T-16.3 Verification that observable behavior is unchanged
  - Compare procest UI/API behavior before and after migration
  - Component state, error handling, async flows should be identical
  - **Acceptance:** No behavioral regressions; all workflows work as before

---

## Notes

- **Test fixtures:** If existing test fixtures use phantom methods, they must be updated as part of the respective store migration task.
- **Backwards compatibility:** No database migration needed; OR object schemas are unchanged.
- **Deployment:** This is a low-risk internal refactoring; can be deployed as a regular app update once tests pass.
