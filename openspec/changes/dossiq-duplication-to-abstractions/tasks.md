# Tasks: dossiq-duplication-to-abstractions

This is an **umbrella change**. It owns no code. Each cluster below is its own
change, its own branch and its own PR into `development`; this file is the
sequence and the scoreboard.

`openspec validate --strict` will fail on this change because it carries no
delta specs, and it should. The requirements live in the per-cluster changes.
Do not silence it with a placeholder capability.

**Measured 2026-09-10.** Schema counts come from
`lib/Settings/dossiq_register.json`; the baseline is 81 schemas.

## 0. Programme setup

- [x] 0.1 Inventory every dossiq schema against OpenRegister's entities and
      leaves. 81 schemas, **33 in 8 clusters** with a verified counterpart.
      Recorded in `proposal.md`.
- [x] 0.2 Confirm the counterparts by opening the file AND comparing columns
      to properties, not by matching a name. This step removed six schemas the
      first pass had claimed: `abonnement` and `notificationChannel` are ZGW
      Notificaties API records and not OpenRegister subscriptions,
      `aiAuditEntry` logs AI suggestions a user rejected and so has no row in
      an object audit trail, `mapLayer` and `wmsLayer` are basemap
      configuration where `MapLink` is a pin, and `caseShare` is a
      password-protected public link where `FederatedShare` is federation.
      `usageRights` moved clusters rather than being dropped: it is ZGW
      `gebruiksrechten`, document metadata, so it belongs with documents.
      The rejected rows are tabled in `proposal.md` on purpose: a name match
      is the mistake this programme is most likely to repeat.
- [x] 0.3 Grep the sibling checkouts for duck-typed reads of any dossiq slug
      named here, so the Remove step of each cluster knows who else breaks.
      Ran 2026-09-10 over openregister, decidesk, docudesk, doriath,
      app-versions and concurrentie-analyse.

      **Result: no sibling app reads a dossiq slug.** Every hit was the
      sibling's OWN schema of the same name in its own register, which is a
      different and larger finding:

      | App | Slugs it declares itself | Reads dossiq's? |
      |---|---|---|
      | decidesk | `adviceRequest`, `adviesAanvraag`, `advisoryBody` | no |
      | openregister | `tenant`, `adviesAanvraag`, `aiAuditEntry`, `abonnement` | no |
      | docudesk, doriath | `tenant` | no |
      | app-versions | none | no |

      So the advice cluster is duplicated **twice over**: six schemas inside
      dossiq, and a second set inside decidesk. That raises the value of
      cluster 3.1 and means its target must be checked against decidesk's
      shape too, not only dossiq's.

      It also means the Remove steps carry less cross-app risk than feared:
      no duck-typed lookup elsewhere resolves to a dossiq slug, so removing
      one cannot silently no-op another app. Re-run before each removal
      anyway (5.3): this measures today, not the day of the merge.

      ⚠️ Do not read this as "the advice work has no owner elsewhere".
      `dossiq-decisions-to-decidiq` (10/16) and `migrate-committees-to-decidiq`
      (6/10) are already moving some advice and committee surfaces INTO
      decidesk. Cluster 3.1 must reconcile with those two before it proposes
      a target, or dossiq will migrate a schema that another change is
      simultaneously relocating.

## 1. Library gaps, lifted once (nextcloud-vue)

Blocks clusters 3.1, 3.2 and 4.2. Additive, so it can land before any of them.
`development` there is gated: merge needs `--admin`.

- [x] 1.1 `cnFormFieldRenderer.js`: add `field.type === 'file'`. Needed by the
      task form's upload; no other field type is missing.

      **Done.** nextcloud-vue #1083 (`83a4332fb`). `DEFAULT_COMPONENT_MAP` in
      `src/composables/cnFormFieldRenderer.js:124` maps `file: CnFileField`, and
      the manifest schema admits `"file"` in the formField type enum
      (`src/schemas/app-manifest.schema.json:900`). Traced end to end: a
      declared field reaches `CnFormPage.vue:579`, which calls
      `cnRenderFormField`, which returns `CnFileField`. Pinned by
      `tests/composables/cnFormFieldRenderer.spec.js:130`. Mutation-checked on
      2026-09-11: blanking the map entry reddened that test and no other.
      `tests/components/CnFormPageFileField.spec.js` pins the same path through
      the real page, up to the submitted `data:` URL.

      The renderer is still the integration point. A report that it holds
      neither `'file'` nor `CnFileField` was read from a stale tree.
- [x] 1.2 `CnObjectListWidget`: the document list on a case, rendered from the
      manifest instead of `DossierTab`. Unblocks documents-on-the-case 2.2.

      **Done, and not by the mechanism this task first named.** The original
      wording asked for a `$ref` column resolved to a label. That already
      existed before this programme: the built-in `fkResolve` cell widget
      (`CnFkResolveCell`, added in `f02d8d5fe`), which this widget already
      forwarded `widget` and `widgetProps` to (`df2586c53`). The 2026-09-10
      recheck under documents-on-the-case 2.2 found the task still blocked, and
      named the three real gaps.

      All three shipped in nextcloud-vue #1090 (`f8ad729ba`): `content.extend`
      becomes OpenRegister's `_extend[]`, which is what makes a dotted column
      key such as `informatieobject.title` resolve to a value instead of six
      copies of one uuid; `content.rowActions[]` carries the Versions action;
      `content.dropZone` carries the upload. Pinned by the 18 tests in
      `tests/components/CnObjectListWidgetExtendActionsDrop.spec.js`.
      Mutation-checked on 2026-09-11: dropping the `_extend` forwarding reddened
      `forwards content.extend as OpenRegister _extend` at line 57, alone.

      **It does NOT retire `DossierTab`, so documents-on-the-case 2.2 stays
      open.** Measured 2026-09-11: `DossierTab` now groups rows by
      `informatieobjecttype`, multi-selects, sorts, facets on keywords and
      carries its own upload button, none of which `CnObjectListWidget`
      expresses. Swapping it would remove shipped capability, including the
      keyword filter documents-on-the-case 2.3 exists to deliver, while looking
      correct. The two options are written out on that task; the choice is
      Ruben's, not the library's.
- [x] 1.3 `CnIndexPage`: let a column's `link` name a route. Unblocks
      contacts-domain 3.6.

      **Done.** nextcloud-vue #1083, second commit. A fixed `widgetProps.route`
      already worked; the half contacts-domain 3.6 turns on did not. That half
      is now `linkRouteName()` in
      `src/components/CnCellRenderer/CnCellRenderer.vue:420`, where
      `widgetProps.routeField` names a sibling field and `widgetProps.routeMap`
      maps its values to page ids. So a Requester column can send a person to
      `ContactDetail` and an organisation to `OrganisationDetail`.

      The chain is CnIndexPage, then CnDataTable, which forwards `col.widget`
      and `col.widgetProps` at `CnDataTable.vue:185`, then CnCellRenderer.
      Pinned by `routes each row to the page its sibling field maps to` in
      `tests/components/CnCellRenderer.spec.js:322`. Mutation-checked on
      2026-09-11: disabling the map lookup reddened that test, alone. A sibling
      test at line 349 pins that a row value can never name a page the manifest
      did not declare.
- [ ] 1.4 `actionsDispatcher.js`: an action `type: "run-action"` that runs a
      flow node, and a `@pick:` config token. Unblocks documents-on-the-case
      3.3.

      **Not done, and the old wording hid that.** This task used to read
      "dispatch a declared action by name". Read literally that has been true
      for a long time: `dispatchAction()` at
      `src/utils/actionsDispatcher.js:542` resolves `action.handler` against
      `context.handlers` and calls it, pinned by
      `tests/utils/actionsDispatcher.spec.js:18`. Confirming the symbol exists
      proves nothing here, because nothing about it was ever missing.

      What documents-on-the-case 3.3 actually waits on, measured against
      nextcloud-vue `development` at `f8ad729ba` on 2026-09-11, is an action of
      `type: "run-action"` carrying `node`, `subject` and a `config` whose
      values may use a `@pick:` token. Neither exists. The action type enum at
      `src/schemas/app-manifest-v2.schema.json:1648` reads `handler`,
      `open-modal`, `open-page`, `navigate`, `object-op`, `export`, `open-form`,
      `refresh`, `api-call`, `agent`, `toggle`. There is no `@pick` sentinel
      anywhere in `src/`.

      Two lookalikes to not mistake for it. `run-action` does appear in
      `CnSetupWizard.vue`, but that is a wizard STEP type that posts to an
      endpoint, a separate vocabulary the dispatcher never sees. And the
      `agent` type is the nearest shape in the action family, but it runs a
      hermiq agent, not a flow node.

      **And it is not a library gap alone.** OpenRegister has no endpoint that
      runs ONE registered node out of graph: `POST /api/flows/{id}/run` takes
      a flow uuid and 404s `No such flow` otherwise, and
      `GET /api/flow/node-catalog` answers no parameter metadata a `@pick:`
      could read its options from. So a `run-action` type in this library would
      have no server to call. Four decisions (the endpoint, what `@pick:`
      names, whether a suspending token belongs in the closed vocabulary at
      all, and the `run-action` name collision) are written out on
      documents-on-the-case 3.3. This item should probably leave section 1.
- [ ] 1.5 Docs page + JSDoc per changed prop, `check:docs` and `check:jsdoc`
      green, baseline bumped only if coverage genuinely improved.

      **Green for everything that has landed, so it reopens when 1.4 does.**
      Run on 2026-09-11 in a clean clone of nextcloud-vue `development` at
      `f8ad729ba`. `npm run check:docs` exits 0: 492 of 492 public exports
      documented, 259 of 259 component docs covering their props and slots.
      `npm run check:jsdoc` exits 0: all 269 components meet their baseline.

      The baseline moved once and it moved up. #1083 ADDED `"CnFileField": 1`,
      which is the highest bar the script has, for a component that measures
      100 percent at 7 of 7. No existing entry was lowered.

      One trap for whoever runs this next. `check:jsdoc` exits **2** with
      "Failed to load vue-docgen-api" until `cd docusaurus && npm ci` has run,
      because it loads that package from `docusaurus/node_modules` rather than
      the root install. That failure looks like a docs defect and is not one.
      `npm install` at the root is not enough.

## 2. Wave 1 — the task spine

`caseTask` (1 schema, 15 properties, 154 references across 57 files) onto
OpenRegister's `Task`. Everything in wave 2 depends on this.

**The target is not a plan, it is deployed.** Verified on the running
instance 2026-09-10:

- `oc_openregister_tasks` exists with **57 columns** and **18 rows**, beside
  `task_audit`, `task_candidates`, `task_projections`, `task_relations` and
  `task_sequences`.
- `GET /apps/openregister/api/flow-tasks` answers **200** with real rows.
- The full verb surface is routed: `claim`, `unclaim`, `assign`, `reassign`,
  `delegate`, `offer`, `resolve`, `complete`, `cancel`, `audit`, and
  `PATCH /checklist/{itemId}`.

So this migration is an integration against a working API, not a wait on
somebody else's roadmap. That is the single biggest de-risking fact in this
programme and the reason wave 1 goes first.

- [x] 2.1 Pin: `CreateTaskHandlerTest`, `TaskCompletionResumeListenerTest`
      and `DossiqAskPersonNodeTest` already existed and cover current
      behaviour; the gateway and backfill added 19 more, each of the
      load-bearing ones mutation-checked (drop the RBAC flags, drop entity
      normalisation, disable dedup, rethrow the engine failure, emit empty
      strings: each reddens exactly one test and only that one).
- [x] 2.2 Map: the 15 `caseTask` properties onto `Task` columns. **Done, and
      all 15 map. Nothing is orphaned and almost nothing needs translating.**

      | caseTask | Task column | Note |
      |---|---|---|
      | `title` | `title` | 1:1 |
      | `description` | `description` | 1:1 |
      | `status` | `state` | **1:1.** `Task::STATES` is the same CMMN vocabulary: available, active, completed, terminated, disabled (plus `enabled`, which dossiq does not use). `TERMINAL_STATES` is the same three. |
      | `isTerminalStatus` | `is_terminal` | Already materialised there; dossiq's calculation can go |
      | `case` | `object_uuid` + `register_id` + `schema_id` | The case IS the object (OR design D-3) |
      | `assignee` | `assignee` | 1:1 |
      | `assigneeGroup` | `candidate_groups` | Single value into an array |
      | `dueDate` | `due_at` | 1:1 |
      | `priority` | `priority` | **1:1.** `TaskPriority::STRINGS` canonical four are low/normal/high/urgent, exactly dossiq's enum |
      | `completedDate` | `completed_at` | 1:1 |
      | `workflowStepId` | `workflow_step_id` | 1:1 |
      | `checklist` | `checklist` | JSON-encoded string here, real `json` there. A widening, not a loss |
      | `flowRun` | `run_uuid` | 1:1 |
      | `flowNode` | `node_id` | 1:1 |
      | `blocksCase` | a `TaskRelation` row | No column by design: OR keeps typed relations out of the task row |

      **The `'open'` defect is already fixed here, and the citation is
      stale.** OpenRegister's `flow-task-entity` proposal cites
      `procest/lib/Service/Transitions/CreateTaskHandler.php:76` for writing
      an out-of-enum `'open'`. Dossiq fixed that in #1326
      (`fix(transitions): create tasks with a status the schema allows`); the
      line now writes `'available'` and only the explanatory comment mentions
      the old value. Nothing to carry across.

      Keep OpenRegister's refusal test anyway: `TaskState::normalise()`
      refuses an unmapped status naming itself, and that guard is what stops
      the next app reintroducing it. Tell the openregister side their
      citation is fixed so the proposal stops describing a live bug.

      **Forty Task columns have no caseTask source**, all optional, and they
      are what dossiq gains: `responses` and `template_snapshot` (the task
      form dossiq cannot have today), `sla_value`/`sla_unit`,
      `candidate_users`/`candidate_role`/`routing_strategy`, `watchers`,
      `on_timeout`/`on_reject`, `parent_task_id`/`epic_task_id`,
      `sequence_uuid`, `evidence`, `percent_complete`.
- [x] 2.3a Dual-run, WRITE half. `EngineTaskGateway` mirrors every task the
      transition engine creates, behind `task_engine_write`, and
      `occ dossiq:tasks:mirror --actor=<uid>` backfills the ones that already
      existed. Verified on the running instance: 33 written, a second run
      reports 33 already present, and the table holds 33 keyed rows. The
      state split (15 active, 13 completed, 5 available) confirms on live
      data that the CMMN vocabulary is shared, which the map had asserted.
      Four defects found only by running it, all of which reported success:
      the positional `_rbac`/`_multitenancy` parameters passed as config
      keys, entities filtered out by an `is_array()` check, `create()`
      refusing terminal states where `import()` is the trusted path, and a
      docblock claiming an idempotency the code did not have.
- [x] 2.3b Dual-run, READ half: `useEngineTaskStore`
      (`src/store/modules/engineTask.js`) reads the engine's list, one task,
      and its lifecycle verbs. No surface consumes it yet; the six that will
      are enumerated in `remove-casetask` task 1.
- [x] 2.9 The flow-resume path is migrated, and it was the load-bearing
      part. `TaskCompletionResumeListener` listened to `ObjectUpdatedEvent`
      on a `caseTask` row; once nothing writes such a row it would never
      fire again and a suspended run would only recover on
      `DossiqAskPersonNode`'s 30-minute heartbeat. It now listens to the
      engine's `TaskTerminalEvent`, refuses an uncommitted event, and
      resumes only on `completed` (the engine fires the event for all three
      terminal states, and `terminated`/`disabled` are the ask being
      withdrawn). Both guards mutation-checked.
- [ ] 2.4 The dossiq task detail page reads `Task` and keeps its own surface
      (D-3): same route, same page id, same deep links, case card and the two
      leaves intact.
- [ ] 2.5 `DossiqAskPersonNode` carries a form, so the answer is recorded and
      not merely the fact of an answer. This is the capability dossiq cannot
      have today and gets for free from the abstraction.
- [ ] 2.6 Confirm the VTODO projection reaches NC Tasks for a dossiq task, and
      that ticking it off there completes the engine task through
      `TaskVtodoWriteBackGate`. Dossiq writes no CalDAV code (D-4).
- [ ] 2.7 Remove `caseTask`. **Planned in full as its own change,
      `openspec/changes/remove-casetask/`**, because it is 70 files and two
      of them are rewrites rather than repoints: `Tasks` and `TaskDetail` are
      generic manifest pages that bind a register and a schema, and the
      engine is not an OpenRegister object, so both become `type: custom`.
      The done test is a grep over the WHOLE repo, not `lib/ src/ tests/`:
      seed data, fixtures and `ci-seed.sh` all carry the slug, and a miss in
      `ci-seed.sh` exits before Playwright starts, reporting every spec as
      NOT RUN rather than as one broken seed.
- [ ] 2.8 e2e: a task created by a transition, completed with a form, resuming
      a suspended flow run.

## 3. Wave 2 — the clusters that become tasks

- [ ] 3.1 **Advice** (6 schemas, 58 properties): `adviceRequest`,
      `adviceResponse`, `adviesAanvraag`, `bacAdviceRequest`, `advisoryBody`,
      `advisoryReport`. Three of these are the same concept in two languages.
      Absorb the request/response mechanics into `Task` + task form; leave
      Awb-specific fields on a dossiq schema where they are genuinely legal
      procedure (D-7). Needs 1.1.
- [ ] 3.2 **Inspection checklists** (7 schemas, 57 properties):
      `inspectieChecklist`, `inspectionChecklist`, `inspectionChecklistTemplate`,
      `inspectionChecklistRun`, `inspectionResult`, `checklistItem`,
      `inspectieRapport`. Three of the seven are checklist templates;
      `inspectieChecklist` and `inspectionChecklistTemplate` share 4 of 5
      fields. Onto task forms + the `field-inspection` leaf. Needs 1.1.
      Note this supersedes the archived `inspection-forms-via-forms-leaf`
      decision, which routed the same surfaces through the NC Forms leaf; say
      so explicitly in that change rather than leaving two live answers.

## 4. Wave 3 — the independent clusters

Each stands alone. Six already have a dossiq change that stalled; those are
continued, not replaced.

- [ ] 4.1 **Tenancy** onto `Organisation`. Existing change now at 3/5: the
      MAP is done (#2333) and it changed the shape of the work. `tenant`
      itself maps almost completely once renames are allowed, but five of its
      six satellites have nowhere to go: OpenRegister has no configuration
      store, no billing events, no per-membership role or assurance level,
      and three fixed quota columns where dossiq has generic quota rows.
      Four decisions (2a-2d in that change) now block the Move step.
      `tenantOnboardingTask` is re-filed to the task cluster: it is a step, a
      completedBy, a completedAt and a blockedReason, which is a `Task`.
- [ ] 4.2 **Documents** onto `File` + the files leaf (7 schemas, including
      `usageRights`, which is ZGW `gebruiksrechten` and belongs here rather
      than with access control). Existing
      change at 11/13, both remaining tasks blocked on 1.2 and 1.4.
- [ ] 4.3 **Workflow / case plan** onto `Flow` + `CaseItem` (2 schemas).
      Existing change `retire-cmmn-caseplanstate` at 0/16. The largest of the
      wave-3 items.
- [x] 4.4 **Federation onto `FederatedShare`: REJECTED at field level.**
      One of `caseFederatedShare`'s eleven properties and one of
      `casetransfer`'s thirteen find a column. `fieldSnapshot`,
      `sharedDocuments` and `revokedAt`/`revokedBy` have nowhere to go, and
      `casetransfer` is a custody workflow (`custodyAuditTrail`,
      `idempotencyKey`, source and target organisations, rejection reason),
      which is a different concept from a share. Either OpenRegister grows
      those fields first or these stay. Recorded rather than deleted so the
      next audit does not re-propose it.
- [x] 4.5 **Case location onto `MapLink`: REJECTED at field level.**
      `MapLink` is a pin (`lat`, `lng`, `objectUuid`, `name`, `category`).
      `case-location` carries BAG `addressDesignationId`, BRK `parcelId`,
      `accuracyRadius` and a `source` provenance distinguishing a
      BAG-validated address from a geocoded guess. Dropping those loses a
      legal distinction.
- [ ] 4.6 **Contacts** (existing change at 12/16) and **email** (at 8/20) are
      already in flight against the contacts and email leaves. Continue.

## 5. Done

- [ ] 5.1 Every cluster graded `field-verified`, `owned elsewhere` or
      `mechanics only` in the proposal has reached its own done state. There
      is deliberately NO target schema count: two attempts at one were both
      wrong, and a total hides the difference between a clean row and a
      partial one.
- [ ] 5.2 Every retired slug returns zero hits from a case-insensitive
      `git grep` across the whole repo, including seed data, demo data, e2e
      fixtures and `ci-seed.sh`.
- [ ] 5.3 No sibling app's duck-typed lookup points at a removed slug
      (the table from 0.3, re-run).
