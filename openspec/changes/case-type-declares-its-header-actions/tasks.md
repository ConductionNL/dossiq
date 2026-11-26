# Tasks: case-type-declares-its-header-actions

### Task 1: The case page reads the case type's header row
- **spec_ref**: `openspec/changes/case-type-declares-its-header-actions/specs/case-type-header-actions/spec.md#requirement-a-case-type-declares-the-header-row-of-its-cases`
- **files**: `src/manifest.json` (CaseDetail header), nextcloud-vue CnDetailPage (`headerActionsFrom`), `lib/Settings/register.d` (caseType schema: `headerActions`, `primaryAction`)
- [x] The caseType schema declares `headerActions` (array of strings) and `primaryAction` (string); schema version bumped (register.d/90-case-type-header-actions.json, caseType 1.52.0; tests/Unit/Settings/CaseTypeHeaderActionsTest.php)
- [x] The case page orders and promotes header actions from `@context.caseType.headerActions` / `primaryAction`; without them the row is unchanged
  - `src/menu-layout.simple.json` CaseDetail: `quickActionsFrom: [@context.caseType.headerActions, @context.caseType.quickActions]`, `primaryActionFrom: @context.caseType.primaryAction`; nextcloud-vue raised to ^2.213.0, the first release with both (nextcloud-vue#2318).
- [x] An id the page does not declare, or whose `visibleWhen` fails, is left out
  - nextcloud-vue CnDetailPage `effectiveQuickActions` / `recordPrimary` keep only declared `headerActions` ids, and `stagePrimary` takes the named primary only while its `visibleWhen` shows it.
- [x] Test: vitest over the manifest and a library test for the ordering and the primary button
  - `tests/vitest/caseTypeHeaderActions.spec.js` over the assembled manifest; the library tests ship with nextcloud-vue#2318 (CnDetailPage suites).

### Task 2: The shipped complaint and grant case types declare their rows
- **spec_ref**: `openspec/changes/case-type-declares-its-header-actions/specs/case-type-header-actions/spec.md#requirement-the-shipped-complaint-and-grant-types-declare-the-boards-rows`
- **files**: the register seed of the complaint and subsidie case types
- [ ] Complaint: schedule-hearing, edit, record-finding (primary)
  - Waits on the acts: the case page declares neither `schedule-hearing` nor `record-finding` (25 Nov 2026). Update 25 Nov 2026: scheduling is now the generic `session-schedule` (complaints-are-a-case-type 2.3), so the row's id for Schedule hearing is `session-schedule`; `record-finding` still has no header action. Scheduling a hearing waits on `case-session-with-parties-and-minutes` 2.3 / `complaints-are-a-case-type` 2.3; recording the verdict has no header action yet. Edit is the page's own button, never a header action id, so it needs no declaration. The complaint type (`klacht-behandeling`) declares its row once both ids exist; the guard test below then holds it to them.
- [ ] Grant: pay-advance, amend-decision, edit, adopt (primary)
  - Built: Adopt is settling the grant, the page's `grant-settle`; the subsidie package declares `primaryAction: grant-settle` (`lib/Settings/packages/subsidie/case-type.json`, package 1.10.0). Edit is the page's own button. Open: `pay-advance` and `amend-decision` have no header action on the case page yet (`grant-cases-from-application-to-settlement` 1.2 builds the grant acts); add them to `headerActions` when they exist.
- [x] Test: the seed declares only ids the case page declares
  - `tests/vitest/caseTypeHeaderActionsSeed.spec.js` walks every JSON file under `lib/Settings` and fails on any `headerActions`, `primaryAction` or `quickActions` id the assembled CaseDetail page does not declare (seen red with `adopt` in place of `grant-settle`).

### Task 3: Live pass
- [ ] compdiff of DqKlachten and DqSubsidie on the demo stack
