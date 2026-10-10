# Tasks: inspection-checklists-onto-task

Opened 10 Oct 2026 by build lane L5 for `dossiq-duplication-to-abstractions`
task 3.2. The five steps of `tenancy-onto-openregister-organisation`, one
checkbox each.

**`openspec validate --strict` fails on this change, on purpose.** It carries
no delta specs, and it gets none until step 3 is decided. A delta written
before the decisions would specify a guess.

## The five steps

- [ ] 1 **Pin.** Every reader and writer of the seven schemas has a test that
      fails when its behaviour changes. Present today:
      `tests/Unit/Service/ChecklistServiceTest.php`,
      `tests/Unit/Service/InspectionChecklistServiceTest.php`,
      `tests/Unit/Controller/InspectionChecklistControllerTest.php` and
      `tests/Unit/Listener/ChecklistRunImmutabilityListenerTest.php`.
      Missing: the mobile store `src/store/modules/inspection.js` (stack A)
      has no vitest, and the portal's `submitChecklistRun` write has no test
      of its own.
- [x] 2 **Map.** Done 10 Oct 2026, written up in the proposal under "What the
      map found". Three stacks, seven schemas. The run maps onto `Task`; the
      template and its typed items do not. Five decisions came out of it
      (3a to 3e).
- [ ] 3 **Decide.** 3a to 3e in the proposal, asked as Q-dossiq-L5-3.
- [ ] 4 **Move.** Not written yet. It depends on 3b and 3d. The reversible
      half comes first, as in the tenancy change: read from `Task`, write to
      both, then write to `Task` only.
- [ ] 5 **Remove.** Retire the two template schemas that 3a does not keep and
      the three result schemas. The test for "gone" is the one in the
      umbrella's task 5.2: no slug as a string a store is asked for in
      `lib`, `src` or `appinfo`.
