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

- [ ] 3.1 `lib/Service/Actions/MergeTemplateHandler.php`: when
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
- [ ] 3.2 `src/manifest.json` page `CaseDetail`: header action
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

## 4. Seed

- [ ] 4.1 `lib/Settings/register.d/46-demo-cases-english.json` (or the
  dossier seed beside it): two `informatieobject` rows on one demo case
  with different types, one tagged `bezwaar`, directions incoming and
  outgoing, linked through `zaakinformatieobject`; one library template
  Ontvangstbevestiging with a `documentType`.
  - unit test in `tests/Unit/Settings/DemoDossierSeedTest.php`: the rows
    exist, the link rows reference the case, the keyword is present

## 5. Verification

- [ ] 5.1 Add `tests/e2e/case-documents.spec.ts` covering every scenario of
  the three delta specs that names it: the Documents tab with seeded rows
  and six columns, the empty tab, drop a file through the metadata dialog,
  Versions on a row, keywords on upload and the keyword filter, direction
  on upload, the template picker listing the library, Generate document
  landing a draft outgoing row. Assert ids and column headers, not widget
  type, so 2.2 and 3.3 do not rewrite the spec. Seed through `seedCase`
  and `createObject` from `tests/e2e/helpers/fixtures.ts`; clean up with
  `cleanupRunObjects`.
- [ ] 5.2 Update `tests/e2e/case-detail-kpis-and-tabs.spec.ts`: the tab
  strip holds Documents before Files.
- [ ] 5.3 Run `composer check:strict`, `npm run check:manifest`, the hydra
  gates and the unit suite locally; read the exit codes, not the summaries.
