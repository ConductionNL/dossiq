# Tasks: documents-on-the-case

Tier: V1. Kind: config. Every checkbox below is one implementation task; the
criteria under a task are plain bullets.

## 1. Schema properties

- [x] 1.1 `lib/Settings/register.d/70-document-zaakdossier.json` schema
  `informatieobject`: property `keywords` (`array` of `string`, `maxLength`
  64, title Keywords, `facetable: true`, `x-widget: tags`, optional) and
  property `direction` (`string`, enum `incoming`, `outgoing`, `internal`,
  default `internal`, title Direction, `facetable: true`, optional). Bump
  the schema version to 1.1.0. The property is `keywords`, not
  `trefwoorden` (decisions D13).
  - unit test in `tests/Unit/Settings/DocumentZaakdossierSchemaTest.php`:
    both properties present and optional, `keywords` items are strings,
    `direction` enum and default, no `trefwoorden` key anywhere in the file
  - `@spec openspec/specs/document-zaakdossier/spec.md`
- [x] 1.2 `l10n/nl.json`: Keywords as Trefwoorden, Direction as Richting,
  Incoming, Outgoing and Internal as Inkomend, Uitgaand and Intern.
  - `npm run check:l10n` exits 0 (or the fleet's Dutch-key check)

## 2. The Documents tab

- [x] 2.1 `src/manifest.json` page `CaseDetail`: add widget `case-documents`
  as `type: custom`, `component: DossierTab`, `props.objectId: @objectId`,
  title Documents, icon `FileDocumentMultipleOutline`; add it to
  `case-panels.content.tabs` as Documents before Files; keep it out of
  `layout`.
  - `npm run check:manifest` exits 0
  - `@spec openspec/specs/document-zaakdossier/spec.md`
- [ ] 2.2 [blocked: nextcloud-vue `CnObjectListWidget` rendering a `$ref`
  column by a label field (placement section 3, rows A35 and A36; triage
  #8, Tier D06)] Replace 2.1's widget body with `type: object-list`,
  `register: dossiq`, `schema: zaakinformatieobject`, `filter.case:
  @objectId`, sort `registrationDate` desc, limit 50, columns
  `informatieobject.title`, `informatieobject.informatieobjecttype`,
  `informatieobject.status`, `informatieobject.direction`,
  `informatieobject.creatiedatum`, `informatieobject.auteur`, `emptyText`
  "No documents yet", the `DossierTab` drop handler as `dropZone` and a
  row action Versions opening `VersionHistoryPanel`. 2.1 is the interim.

  RE-MEASURED 2026-09-10 against `@conduction/nextcloud-vue` **2.42.0** as
  installed. THE NAMED BLOCKER SHIPPED AND THE TASK IS STILL BLOCKED, which
  is why the recheck was worth doing rather than reading the changelog.
  `CnCellRenderer` now has a built-in `widget: "fkResolve"`
  (`CnFkResolveCell`, `widgetProps { register, schema, labelField }`) that
  resolves a reference uuid to the referenced object's label through the
  shared object store, and `CnObjectListWidget` forwards `widget` and
  `widgetProps` to it. That is a `$ref` column rendered by a label field,
  word for word what the blocker asked for.
  It does not get this task done, on three counts measured in the same tree.
  ONE, this task needs SIX fields off the referenced `informatieobject`, not
  one label, and `fkResolve` resolves one `labelField` per column.
  TWO, the dotted columns `informatieobject.title` and its five siblings are
  read by `CnDataTable` as paths into the ROW
  (`key.split('.').reduce(...)`), and the row holds a uuid string there,
  because `CnObjectListWidget` builds its query params from `_limit`,
  `_page`, `_order[...]` and the `filter` entries and sends no `_extend`.
  OpenRegister does answer `_extend`, so the seam to ask the library for is
  narrower than it looked: an `extend` key on the widget's content.
  THREE, and decisively, `CnObjectListWidget` matches neither `dropZone` nor
  `rowActions` at all, so it cannot host the `DossierTab` drop handler or the
  Versions row action this task also requires. 2.1 stays the shipped form.

  **RE-MEASURED 2026-09-11. THE LIBRARY BLOCKER IS GONE AND THE TASK IS NOW
  BLOCKED ON A DECISION INSTEAD, so it is left unticked rather than moved.**

  All three counts above were lifted in nextcloud-vue and reach this app in
  **2.47.0**: `content.extend` sends OpenRegister's `_extend[]` so the dotted
  columns resolve off the inlined `informatieobject`, `content.rowActions[]`
  renders per-row actions through `CnRowActions`, and `content.dropZone`
  dispatches a declared action with the dropped `File[]`. That is the seam this
  task asked for, named as `dossiq-duplication-to-abstractions` 1.2.

  What the recheck found instead is that THIS TASK'S PREMISE HAS EXPIRED.
  2.2 was written when `DossierTab` was a thin list, and 2.3 and the work after
  it grew it into a surface `CnObjectListWidget` cannot express. Read out of
  `src/views/cases/components/DossierTab.vue` today, the interim ships:

  - rows GROUPED by `informatieobjecttype`, rendered through `DossierGroup`,
    not one flat table;
  - a multi-select (`selectedIds`) over those rows;
  - a sort dropdown and a keyword multi-filter faceted on the keywords actually
    in use, both added by 2.3, which is TICKED;
  - an Upload document button and file picker beside the drop overlay;
  - open-in-Files, version history and delete per row;
  - the document count in the tab title.

  Swapping in `type: object-list` would therefore REMOVE shipped capability —
  including the keyword filter that 2.3 exists to deliver — and would do it
  silently, because a flat table of the right rows looks like a working
  Documents tab. That is a regression dressed as an abstraction, and it is the
  opposite of what this task was for.

  So the decision is Ruben's, and it is not a library question any more:

  **(a) Close 2.2 as superseded.** Keep `DossierTab`, record that the
  abstraction was built and that the surface outgrew it. The three new library
  keys still pay for themselves elsewhere in the fleet.

  **(b) Grow `CnObjectListWidget` further** — row grouping, a facet control and
  multi-select — and then swap. That is a much larger library ask than the one
  this task raised, and it should be its own change with its own measurement,
  not a silent widening of this one.

  Recorded rather than chosen, because picking (a) quietly would retire a
  capability nobody agreed to retire, and picking (b) quietly would commit the
  library to three more seams on one app's say-so.
- [x] 2.3 `src/views/cases/components/DossierTab.vue`: render the Direction
  and Keywords columns (chips), add the keyword filter (facet on
  `keywords`) beside the sort dropdown, and the empty state "No documents
  yet". Keep the drop overlay and the version panel as they are.
  - vitest in `src/views/cases/components/DossierTab.spec.js`: six column
    headers, empty state, keyword filter narrows the rendered rows
  - `@spec openspec/specs/document-zaakdossier/spec.md`
- [x] 2.4 `src/modals/DocumentMetadataDialog.vue`: a tags input for
  `keywords` under the title and a select for `direction` beside the type,
  both sent with the upload metadata.
  - vitest in `src/modals/DocumentMetadataDialog.spec.js`: the emitted
    metadata carries `keywords` and `direction`; `direction` defaults to
    `internal`
  - `hydra-gate-nc-input-labels`: every `NcSelect` has an `inputLabel`

## 3. Generate document

- [x] 3.1 `lib/Service/Actions/MergeTemplateHandler.php`: when
  `targetField` is absent, create an `informatieobject` (title from the
  template name, `status: draft`, `direction: outgoing`, `auteur` the
  signed-in user, `informatieobjecttype` from the template's
  `documentType` when set) and a `zaakinformatieobject` linking it to the
  case; keep the `targetField` branch unchanged; a failed render creates
  nothing. `requiredConfigKeys()` on `DossiqMergeTemplateNode` drops
  `targetField`.
  - unit tests in `tests/Unit/Service/Actions/MergeTemplateHandlerTest.php`:
    `targetField` present writes the case field and no object; absent
    creates both objects with the expected fields; missing field fails
    without objects; `documentType` follows into the informatieobject
  - `composer check:strict` exits 0; no new `StaticAccess` in phpmd
  - `@spec openspec/specs/beschikking-generatie/spec.md`
  - `@spec openspec/specs/template-library/spec.md`
- [x] 3.2 `src/manifest.json` page `CaseDetail`: header action
  `generate-document`, `type: open-modal`, `modal:
  BeschikkingComposerDialog`, `props.caseId: @objectId`, label Generate
  document, icon `FileDocumentPlusOutline`, `successMessage` "Document
  added to the case."; `src/dialogs/BeschikkingComposerDialog.vue` lists
  templates from `TemplateController#index` by name and calls the handler
  path from 3.1 without a `targetField`.
  - `npm run check:manifest` exits 0
  - `@spec openspec/specs/beschikking-generatie/spec.md`
- [ ] 3.3 [blocked: nextcloud-vue `actionsDispatcher.js` dispatching a
  `run-action` header action (node, subject, config with a `@pick:`
  token)] Replace 3.2's action with `type: run-action`, `node:
  DossiqMergeTemplateNode`, `subject: @objectId`, `config.templateSlug:
  @pick:template`, and drop the dialog. 3.2 is the interim.

  RE-MEASURED 2026-09-10 against 2.42.0. STILL BLOCKED. The action `type`
  enum in the installed `src/schemas/app-manifest-v2.schema.json` reads
  `handler`, `open-modal`, `open-page`, `navigate`, `object-op`, `export`,
  `open-form`, `refresh`, `api-call`, `agent`, `toggle`. There is no
  `run-action`, and no `@pick:` token in the sentinel grammar. The `agent`
  type added since is the closest shape in the family and runs a hermiq
  agent, not a flow node.

  **RE-MEASURED 2026-09-11 against nextcloud-vue `development`. STILL BLOCKED,
  and this is NOT one library seam. It is four decisions, and it stops here
  until they are taken.** Written out so the next recheck does not have to
  find them again.

  **D-1. There is no server to talk to.** OpenRegister routes
  `POST /api/flows/{id}/run`, where `{id}` is a FLOW uuid: `FlowController::run`
  calls `$this->flows->run(uuid: $id, …)` and 404s with `No such flow`
  otherwise. Nothing anywhere executes ONE registered node out of graph with a
  subject and a config blob. `GET /api/flow/node-catalog` answers only
  `{id, displayName, description, icon}` per node — no config-key schema, no
  parameter metadata. So `run-action` would need OpenRegister to grow an
  endpoint first, and this change's own `design.md` already rejects the
  neighbouring shape for the same reason: "a route that runs a Flow node by
  name is engine work and a second way to run an action next to the Flow
  runtime". That objection applies to OpenRegister growing the route too.
  Sub-questions it drags in: does an out-of-graph run get a run log? which RBAC
  action gates it, given `flow.run` is graph-scoped? does a node an app
  contributes become directly HTTP-invokable by anyone who can name it?

  **D-2. `@pick:template` names nothing.** Nothing in the manifest says where
  the options come from, and the one server surface that describes a node
  returns no parameter metadata, so the option source, the label field, the
  value field and the dialog title would all be new manifest fields. It is a
  new product surface, not a token spelling.

  **D-3. A suspending token is a new resolver contract.** Every token in
  `src/utils/sentinelTokens.js` resolves SYNCHRONOUSLY from ambient context —
  the clock, the signed-in user, route params, the page object, app config.
  `@pick:` would be the first that opens UI and waits for a person: async
  resolution, a cancel path, and a decision about whether the closed vocabulary
  admits interactive tokens at all — or whether "ask, then run" stays what it
  is today, a dialog, which is exactly what 3.2 shipped.

  **D-4. The name is taken.** `run-action` is already a setup-wizard STEP type
  in the same schema file, posting to `/api/setup/action/{action}`, and is
  surfaced in the editor as "Run action (call an endpoint)". A second
  `run-action` with flow-node semantics needs either a rename or a deliberate
  overload.

  One path IS open today and does not finish the task: `type: api-call` against
  `POST /api/cases/{caseId}/dossier/generate`, which exists, is authorized and
  is what 3.2's dialog already calls. `design.md` rejects it, and it would not
  drop the dialog anyway, because `api-call` has no picker — so the template
  choice would have to be hardcoded per action. Noted so it is not
  re-discovered as an escape hatch.

  Also worth carrying to whoever takes D-1: the dialog sends `templateId`
  while `DossiqMergeTemplateNode::requiredConfigKeys()` demands `templateSlug`.
  Those two names have to be reconciled by any migration.

  **2026-09-11, Ruben decided "design it now" rather than defer, upstream
  piece included. Two proposals opened, re-verifying all four Ds against
  live `development` code (not re-trusting this file's own account):**

  - `openregister` `feat/or-flow-run-node`
    (`openspec/changes/or-flow-run-node/`): a `POST
    /api/flows/{flowId}/nodes/{nodeId}/run` endpoint, gated by an opt-in
    `IFlowDirectlyInvokable` marker on the node type PLUS the caller's
    object-RBAC permission on the subject — not `flow.run`, which was
    verified to be a flat, subject-blind, `@authenticated`-seeded right that
    on its own would let any signed-in user run this against any case they
    can name the id of. **Its RN-1 (which authorization shape) is an open
    decision for Ruben, not resolved in that proposal** — it is the first
    time OpenRegister's object-RBAC and flow named-rights would need to
    cooperate, and that is fleet-wide surface, not a dossiq detail.
  - `nextcloud-vue` `feat/manifest-run-node-action`
    (`openspec/changes/manifest-run-node-action/`): a `run-node` action type
    (not `run-action`, resolving D-4) that sources its picker from
    OpenRegister's existing `IFlowNodeConfigForm` declaration on the node
    type — resolving D-2 without new manifest grammar — and follows the
    `open-form` precedent (open a dialog, return immediately, let the
    dialog's submit make the follow-up call) rather than inventing an
    async/suspending sentinel token — resolving D-3 without touching
    `sentinelTokens.js` at all. This proposal has no open decision of its
    own; it is blocked only on the openregister endpoint existing.

  **3.3 stays unticked.** Implementation (here and upstream) is blocked on
  Ruben resolving RN-1. 3.2 remains the shipped interim.

- [x] 4.1 `lib/Settings/register.d/46-demo-cases-english.json` (or the
  dossier seed beside it): two `informatieobject` rows on one demo case
  with different types, one tagged `bezwaar`, directions incoming and
  outgoing, linked through `zaakinformatieobject`; one library template
  Ontvangstbevestiging with a `documentType`.
  - unit test in `tests/Unit/Settings/DemoDossierSeedTest.php`: the rows
    exist, the link rows reference the case, the keyword is present

## 5. Verification

- [x] 5.1 Add `tests/e2e/case-documents.spec.ts` covering every scenario of
  the three delta specs that names it: the Documents tab with seeded rows
  and six columns, the empty tab, drop a file through the metadata dialog,
  Versions on a row, keywords on upload and the keyword filter, direction
  on upload, the template picker listing the library, Generate document
  landing a draft outgoing row. Assert ids and column headers, not widget
  type, so 2.2 and 3.3 do not rewrite the spec. Seed through `seedCase`
  and `createObject` from `tests/e2e/helpers/fixtures.ts`; clean up with
  `cleanupRunObjects`.
- [x] 5.2 Update `tests/e2e/case-detail-kpis-and-tabs.spec.ts`: the tab
  strip holds Documents before Files.
- [x] 5.3 Run `composer check:strict`, `npm run check:manifest`, the hydra
  gates and the unit suite locally; read the exit codes, not the summaries.
