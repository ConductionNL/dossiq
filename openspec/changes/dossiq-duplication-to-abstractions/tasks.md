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

- [ ] 1.1 `cnFormFieldRenderer.js`: add `field.type === 'file'`. Needed by the
      task form's upload; no other field type is missing.
- [ ] 1.2 `CnObjectListWidget`: a column that resolves a `$ref` to a label
      instead of rendering the uuid. Unblocks documents-on-the-case 2.2 and
      retires dossiq's `DossierTab` workaround.
- [ ] 1.3 `CnIndexPage`: let a column's `link` name a route. Unblocks
      contacts-domain 3.6.
- [ ] 1.4 `actionsDispatcher.js`: dispatch a declared action by name.
      Unblocks documents-on-the-case 3.3.
- [ ] 1.5 Docs page + JSDoc per changed prop, `check:docs` and `check:jsdoc`
      green, baseline bumped only if coverage genuinely improved.

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

- [ ] 2.1 Pin: tests over `CreateTaskHandler`, `TaskCompletionResumeListener`,
      `DossiqAskPersonNode` and the three task widgets, against current
      behaviour, each mutation-checked.
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
- [ ] 2.3 Dual-run: write to both, read from `Task`, behind a config flag.
- [ ] 2.4 The dossiq task detail page reads `Task` and keeps its own surface
      (D-3): same route, same page id, same deep links, case card and the two
      leaves intact.
- [ ] 2.5 `DossiqAskPersonNode` carries a form, so the answer is recorded and
      not merely the fact of an answer. This is the capability dossiq cannot
      have today and gets for free from the abstraction.
- [ ] 2.6 Confirm the VTODO projection reaches NC Tasks for a dossiq task, and
      that ticking it off there completes the engine task through
      `TaskVtodoWriteBackGate`. Dossiq writes no CalDAV code (D-4).
- [ ] 2.7 Remove `caseTask`. `git grep -i caseTask lib/ src/ tests/` returns
      nothing.
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

- [ ] 4.1 **Tenancy** onto `Organisation` (7 schemas, 50 properties, 56 PHP
      files, five middlewares). Existing change at 2/5: pinning done, the map
      is next.
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
