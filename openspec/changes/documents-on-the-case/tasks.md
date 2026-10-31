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

## 2. The Documents tab (since 2026-09-13 the Files tab)

- [x] 2.1 `src/manifest.json` page `CaseDetail`: add widget `case-documents`
  as `type: custom`, `component: DossierTab`, `props.objectId: @objectId`,
  title Documents, icon `FileDocumentMultipleOutline`; add it to
  `case-panels.content.tabs` as Documents before Files; keep it out of
  `layout`.
  - `npm run check:manifest` exits 0
  - `@spec openspec/specs/document-zaakdossier/spec.md`
- [ ] 2.2 RESCOPED 2026-10-22 to the Files tab (coordinator call). The
  Documents tab this task meant to rebuild was retired on 2026-09-13: the
  case's own folder in the Files tab (`case-files`, nextcloud-vue
  CnFilesBrowser through the `files` leaf) is the one documents surface, and
  it already carries upload, drop, document properties, versions, bulk
  confidentiality and mark-final, and the per-document metadata as row data
  (`rowDataUrl` over `/api/cases/{id}/dossier`). What the Documents tab had and
  the Files tab still lacks is the document's Direction and Keywords and the
  keyword filter. So this task is now: declare Direction and Keywords as row
  columns of `case-files`, both starting hidden (the board draws the tab
  without them; they are one tick away in the Columns chooser), and a
  Keywords facet. Needs nextcloud-vue `hidden` columns
  (files-browser-column-hidden-by-default).
  - vitest on the manifest: both columns from the row, hidden, the facet
    names Keywords
  - `@spec openspec/specs/document-zaakdossier/spec.md`

  The history of the earlier scope (an object-list Documents tab, measured
  against 2.42.0 and 2.47.0, and decision options (a) and (b)) is in git
  history of this file; the Files tab decision supersedes it.
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
- [ ] 3.3 RESCOPED 2026-10-22 to the Files tab (coordinator call). The
  run-action form this task waited on is not needed for what a handler does:
  generating a document from a template is the dialog 3.2 ships
  (`BeschikkingComposerDialog`), and the place a handler looks for "a new
  document" is the Files tab's New menu, beside Request a file. So this task
  is now: `case-files` offers Generate document in its New menu
  (`newActions`), opening the same dialog with the case filled in, on a case
  that is not archived like the header action.
  - vitest on the manifest: the New menu entry, its dialog and its case
  - `@spec openspec/specs/beschikking-generatie/spec.md`
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
