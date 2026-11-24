# Tasks: case-type-declares-its-header-actions

### Task 1: The case page reads the case type's header row
- **spec_ref**: `openspec/changes/case-type-declares-its-header-actions/specs/case-type-header-actions/spec.md#requirement-a-case-type-declares-the-header-row-of-its-cases`
- **files**: `src/manifest.json` (CaseDetail header), nextcloud-vue CnDetailPage (`headerActionsFrom`), `lib/Settings/register.d` (caseType schema: `headerActions`, `primaryAction`)
- [x] The caseType schema declares `headerActions` (array of strings) and `primaryAction` (string); schema version bumped (register.d/90-case-type-header-actions.json, caseType 1.52.0; tests/Unit/Settings/CaseTypeHeaderActionsTest.php)
- [ ] The case page orders and promotes header actions from `@context.caseType.headerActions` / `primaryAction`; without them the row is unchanged
- [ ] An id the page does not declare, or whose `visibleWhen` fails, is left out
- [ ] Test: vitest over the manifest and a library test for the ordering and the primary button

### Task 2: The shipped complaint and grant case types declare their rows
- **spec_ref**: `openspec/changes/case-type-declares-its-header-actions/specs/case-type-header-actions/spec.md#requirement-the-shipped-complaint-and-grant-types-declare-the-boards-rows`
- **files**: the register seed of the complaint and subsidie case types
- [ ] Complaint: schedule-hearing, edit, record-finding (primary)
- [ ] Grant: pay-advance, amend-decision, edit, adopt (primary)
- [ ] Test: the seed declares only ids the case page declares

### Task 3: Live pass
- [ ] compdiff of DqKlachten and DqSubsidie on the demo stack
