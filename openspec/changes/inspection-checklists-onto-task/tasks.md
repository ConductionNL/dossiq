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
  - [x] 4.1 Template fold. Item `id`, `weight`, `parent` and template
        `legacyRef` on `inspectionChecklistTemplate` (1.1.0, both registers,
        digests recorded); repair step `FoldInspectionChecklistTemplates`
        (post-migration, exempt from install with its reason) with
        `tests/Unit/Repair/FoldInspectionChecklistTemplatesTest.php`; the
        mapping in `lib/Service/Inspection/InspectionTemplateMapper.php`
        (`tests/Unit/Service/Inspection/InspectionTemplateMapperTest.php`);
        `InspectionChecklistService` reads and writes the template schema and
        keeps the settings editor's flat shape, so `ChecklistsTab.vue` is
        unchanged (two new cases in `InspectionChecklistServiceTest`); the
        store reads `inspectionChecklistTemplate`, flattening sections
        (`tests/vitest/inspectionStore.spec.js`), and `src/store/store.js`
        registers the type: the panel's template read threw "not registered"
        before. The store's own template writes had no caller and are gone.
  - [x] 4.2 Runs onto Task. `lib/Service/Inspection/InspectionRunService.php`
        creates one `inspection` task (template, version, snapshot,
        metadata `location`/`capturedOffline`/`capturedAt`/`inspection`;
        `syncState` is not stored) and completes it with responses, evidence
        and the outcome; a run that breaks the photo or required gate writes
        nothing. `InspectionAnswers` normalises stack A, C and native answers
        onto ja/nee/nvt and holds the one outcome rule. Both VTH result
        endpoints go through it (`InspectionChecklistController`; the old
        `submitResult`/`getResultsForCase` and their helpers are gone from
        `InspectionChecklistService`). The store submits and reads through
        those endpoints and the panel names items by `id`. Repair step
        `CarryInspectionRunsOntoTasks` imports every `inspectieRapport`,
        `inspectionChecklistRun` and `inspectionResult` once, keyed on
        `metadata.legacyRef` (mapping in `InspectionRunCarryOver`). Tests:
        `InspectionRunServiceTest`, `InspectionAnswersTest`,
        `InspectionRunCarryOverTest`, `CarryInspectionRunsOntoTasksTest`,
        the updated controller test and `inspectionStore.spec.js`; e2e: two
        new tests in `tests/e2e/vth-inspection-result-authz.spec.ts`, whose
        fixture now seeds a template (written, live pass per decision 139).
  - [ ] 4.2b The offline field-inspection leaf (nextcloud-vue builtin,
        configured in `src/main.js`) replays results as object creates on
        `checklistResult` and reads `inspectionChecklist` with a flat
        `items[].questionId` shape. It needs a nextcloud-vue change (decision
        156: build it there): read items from template sections, and replay a
        finished checklist to a configured endpoint (dossiq's
        `POST /api/vth/cases/{id}/inspection-result`) instead of an object
        create. Then repoint `referenceSchema` and drop `checklistResult`.
  - [x] 4.3 Portal inspector on OpenRegister's portal task seam: external
        runs as `performerType: external` tasks; a dossiq endpoint action
        completes one after checking the stored party reference. Done 17 Nov
        2026: `POST /api/cases/{id}/checklist-runs/external`
        (`ExternalInspectionRunController`, case mutation guard first) creates
        an open `inspection` task with `performerType: external`, assignee
        `party:<inspectorRef>`, the frozen template and
        `metadata.completeVia = {app: dossiq, action: completeChecklistTask}`,
        so OpenRegister refuses any other completion and portaliq's task list
        opens dossiq's form. The inspector contribution declares the endpoint
        action `completeChecklistTask` (`taskField: taskId`);
        `POST /api/portal/inspectie/checklist` (`PortalInspectionController`,
        assertion and `inspector` audience first) lands in
        `ExternalInspectionRunService::complete()`, which compares the task's
        stored kind, performer type and party reference with the assertion's
        subject (404 otherwise), checks the answers against the snapshot (422)
        and completes through `PortalTaskService::complete(viaApp: dossiq)`.
        File ids a portal party names are dropped from its answers, so an item
        whose photo gate needs a photo is refused until the portal action
        carries uploads. Tests: `ExternalInspectionRunServiceTest`,
        `ExternalInspectionRunControllerTest`, `PortalInspectionControllerTest`,
        the inspector case of `PortalContributionProviderTest`. Left open:
        portaliq has no checklist field type, so its generic form cannot yet
        render one answer per item; the collections and `submitChecklistRun`
        retire in step 5.
  - [ ] 4.4 Live pass (decision 139).
- [ ] 5 **Remove.** One release after step 4. Retire the six schemas and `checklistResult`, the
      immutability listener, the stack C template paths and the portal
      collections. The test for "gone" is the one in the umbrella's task 5.2:
      no slug as a string a store is asked for in `lib`, `src` or `appinfo`.
