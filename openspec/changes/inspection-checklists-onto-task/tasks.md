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
  - [ ] 4.2b The offline checklist leaf (nextcloud-vue builtin `field-inspection`).
        Built, ticks when the nextcloud-vue release ships and dossiq pins it.
        nextcloud-vue ConductionNL/nextcloud-vue#1454 (change
        `offline-checklist-reads-sections-and-replays-to-an-endpoint`):
        `normaliseChecklistTemplate` reads sectioned templates through config,
        `buildChecklistSubmission` replays a finished run as one POST to a
        configured `resultEndpoint`. dossiq: the config moved to
        `src/integrations/offlineChecklistConfig.js` (template schema,
        sections, `id`/`label`/`responseType`, photo gate `altijd`, endpoint
        `POST /api/vth/cases/{caseRef}/inspection-result` with `checklistId`
        and `inspection`, no `resultSchema`), pinned against the register,
        routes, controller and run service by
        `tests/vitest/offlineChecklistConfig.spec.js`.
        `FoldInspectionChecklistTemplates` also repoints
        `fieldInspection.checklistTemplateRef` from a folded source to its
        template, since the leaf finds the cached template by id
        (`testPlannedItemsNamingAFoldedSourceAreRepointed`). Open: bump
        `@conduction/nextcloud-vue` to the release carrying #1454.
  - [x] 4.3 Portal inspector on OpenRegister's portal task seam: external
        runs as `performerType: external` tasks; a dossiq endpoint action
        completes one after checking the stored party reference.
        `lib/Controller/PortalChecklistTaskController.php`
        (`POST /api/portal/checklist-tasks`, `taskId` in the body, audience
        `inspector`) and `InspectionRunService::completeForParty()`, which
        reads the task through OpenRegister's `PortalTaskService::show()`
        (party check), checks the answers against the frozen checklist and
        completes through `PortalTaskService::complete()` with the rule's
        outcome. The inspector contribution's one write is now the endpoint
        action `completeChecklistTask`; `submitChecklistRun` is gone (it
        wrote a schema nothing reads since 4.2). The carry-over assigns open
        external runs to `party:` + `assignedInspectorRef`. Tests:
        `PortalChecklistTaskControllerTest`, `InspectionRunPortalTest`,
        `testInspectorContributionShape`, `InspectionRunCarryOverTest`. The
        two read collections stay until step 5. The gap where portaliq's
        "Mijn taken" completed through OpenRegister's seam directly, skipping
        the checklist rule, is closed generically: every external checklist
        task carries `metadata.completeVia` (`InspectionRunService::COMPLETE_VIA`,
        set by the carry-over), OpenRegister's seam refuses a direct completion
        of such a task (ConductionNL/openregister#4569), portaliq opens the
        named action instead (ConductionNL/portaliq#1482), and
        `completeForParty()` completes naming dossiq as `viaApp`.
  - [ ] 4.4 Live pass (decision 139).
- [ ] 5 **Remove.** One release after step 4. Retire the six schemas and `checklistResult`, the
      immutability listener, the stack C template paths and the portal
      collections. The test for "gone" is the one in the umbrella's task 5.2:
      no slug as a string a store is asked for in `lib`, `src` or `appinfo`.
