# Tasks: inspection-checklists-onto-task

Opened 10 Oct 2026 by build lane L5 for `dossiq-duplication-to-abstractions`
task 3.2. The five steps of `tenancy-onto-openregister-organisation`, one
checkbox each.

Step 3 was decided on 10 Oct 2026 (decision 175); the change now carries its
delta specs and validates strictly.

## The five steps

- [x] 1 **Pin.** Every reader and writer of the seven schemas has a test that
      fails when its behaviour changes:
      `tests/Unit/Service/ChecklistServiceTest.php`,
      `tests/Unit/Service/InspectionChecklistServiceTest.php`,
      `tests/Unit/Controller/InspectionChecklistControllerTest.php`,
      `tests/Unit/Listener/ChecklistRunImmutabilityListenerTest.php` and, for
      the portal's collections and its `submitChecklistRun` write,
      `testInspectorContributionShape` plus the field-drift test in
      `tests/Unit/Portal/PortalContributionProviderTest.php`. The mobile store
      `src/store/modules/inspection.js` (stack A) and the template seed
      `lib/Repair/Vth/VthChecklistSeeder.php` (stack B) had none. Added 10 Oct
      2026: `tests/vitest/inspectionStore.spec.js` and
      `tests/Unit/Repair/Vth/VthChecklistSeederTest.php`. Mutation-checked:
      dropping the `nvt` term from the result rule reddened one store test of
      eight, and reading the slug from the body instead of `@self` reddened
      one seeder test of six.
- [x] 2 **Map.** Done 10 Oct 2026, written up in the proposal under "What the
      map found". Three stacks, seven schemas. The run maps onto `Task`; the
      template and its typed items do not. Five decisions came out of it
      (3a to 3e).
- [x] 3 **Decide.** Decision 175 (Q-dossiq-L5-3, option 1). Written into the
      proposal, `design.md` (D1 to D7) and three delta specs.
- [ ] 4 **Move.** Three pull requests, in this order (design D7).
  - [ ] 4.1 Template fold. Item `id`, `weight`, `parent` and template
        `legacyRef` on `inspectionChecklistTemplate`; repair step
        `FoldInspectionChecklistTemplates` (stack A and stack C into the one
        schema, once per source); `InspectionChecklistService` and the
        settings tab, and the store's template reads, on the one schema.
  - [ ] 4.2 Runs onto Task. `InspectionRunService` (create and complete one
        inspection task, the outcome rule, the photo and required gates on
        the frozen snapshot); the two VTH result endpoints and the store and
        panel on it; repair step carrying `inspectieRapport`,
        `inspectionChecklistRun` and `inspectionResult` onto tasks, keyed on
        `legacyRef`; `tests/e2e/inspection-runs-on-task.spec.ts`.
  - [ ] 4.3 Portal inspector on OpenRegister's portal task seam: external
        runs as `performerType: external` tasks; a dossiq endpoint action
        completes one after checking the stored party reference.
  - [ ] 4.4 Live pass (decision 139).
- [ ] 5 **Remove.** One release after step 4. Retire the six schemas, the
      immutability listener, the stack C template paths and the portal
      collections. The test for "gone" is the one in the umbrella's task 5.2:
      no slug as a string a store is asked for in `lib`, `src` or `appinfo`.
