# Design: documents-on-the-case

## Context

`informatieobject` (`lib/Settings/register.d/70-document-zaakdossier.json`,
version 1.0.0) carries `title`, `fileName`, `bestandsomvang`, `format`,
`vertrouwelijkheidaanduiding`, `auteur`, `status` (draft, final, archived),
`informatieobjecttype`, `creatiedatum`, `bronorganisatie`, `taal`,
`description`, `link`, `integrity`, `lockedOn` and `fileId`.
`zaakinformatieobject` carries `case`, `informatieobject`,
`natureRelationshipDisplay` and `registrationDate`. `informatieobjecttype`
carries `description`, `informatieobjectcategorie`,
`vertrouwelijkheidaanduiding`, `schema` and a validity window. No property
holds a keyword and none holds a direction.

`DossierTab.vue` (`src/views/cases/components/`, registered as `DossierTab` in
`src/customComponents.js`) takes `objectId`, reads
`/apps/dossiq/api/cases/{id}/dossier`, groups by type, opens
`DocumentMetadataDialog.vue` on a drop or an upload button, and mounts
`VersionHistoryPanel.vue` per row with `download-version` and
`restore-version`. It is linked from no page.

`DossiqMergeTemplateNode` (`lib/Flow/`) wraps `MergeTemplateHandler`
(`lib/Service/Actions/`). Its required config keys are `templateSlug` and
`targetField`; the handler renders the template over the case and writes the
result into the case field named by `targetField`. `TemplateController`
serves the library; `BeschikkingComposerDialog.vue` composes a
conceptbeschikking from a template and the case data.

Pages touched, by manifest id: `CaseDetail` (widget `case-panels`, type
`tabs`; `headerActions` with `link-object`). nextcloud-vue 2.40.0
`actionsDispatcher.js` handles `handler`, `open-modal`, `open-page`,
`navigate`, `export`, `open-form`, `refresh`, `api-call`, `agent`, `toggle`
and `object-op`. It does not handle `run-action`; the `run-action` type
lives only in the setup wizard. `CnObjectListWidget` renders a `$ref` column
as the raw reference (placement section 3, rows A35 and A36).

ADR-032 kind: **config**. Every change is a schema property or a manifest
entry, plus one additive branch in `MergeTemplateHandler`.

## Goals / Non-goals

**Goals:**

- You see the case's documents with type, status, direction, date and
  author, and you drop a file to add one.
- You tag a document with keywords and filter the list on them.
- You generate a letter from a template on the case's Documents tab, and it
  appears in the list.

**Non-goals:**

- The `files` leaf and loose files (triage #2).
- Folders, bulk operations, ZIP export (REQ-ZAK-008).
- Moving the template library to filinq.
- Signing or sending the generated document (REQ-BES-003, REQ-BES-004).

## Decisions

### D1: the Documents tab is an `object-list` over `zaakinformatieobject`, with `DossierTab` as the interim

Target: widget `case-documents`, type `object-list`, `register: dossiq`,
`schema: zaakinformatieobject`, `filter: {"case": "@objectId"}`, sort
`registrationDate` desc, limit 50, columns `informatieobject.title` (Title),
`informatieobject.informatieobjecttype` (Type), `informatieobject.status`
(Status), `informatieobject.direction` (Direction),
`informatieobject.creatiedatum` (Date), `informatieobject.auteur` (Author).
The drop zone is `DossierTab`'s `onDrop` and `performUpload`, which already
write an `informatieobject` and a `zaakinformatieobject`; the row action
Versions opens `VersionHistoryPanel`. The tab goes into
`case-panels.content.tabs` as Documents before Files and stays out of
`layout`.

Five of the six columns live on the referenced `informatieobject`, and
`CnObjectListWidget` does not render a `$ref` column by a label field. Until
it does, the widget is `type: custom`, `component: DossierTab`, with
`objectId: @objectId`. `DossierTab` renders the same six columns, the drop
zone and the version panel today, so the tab is usable from the first
commit and the manifest entry swaps once nextcloud-vue lands.

Alternative rejected: an `object-list` over `informatieobject`. It has no
`case` property; the link is the `zaakinformatieobject` row, and one
document can hang on several cases (REQ-ZAK-001).

### D2: `keywords` is an array of strings, English, with a tags widget

`informatieobject.keywords`: `{"type": "array", "items": {"type": "string",
"maxLength": 64}, "title": "Keywords", "facetable": true, "x-widget":
"tags"}`. English per decisions D13; `nl.json` labels it Trefwoorden.
`DocumentMetadataDialog` gains the field under the title, optional. The
Documents tab gets a `quickFilters` entry per seeded keyword and the facet on
`keywords`. Schema version 1.0.0 to 1.1.0.

Alternative rejected: reusing `informatieobjecttype` as a label. A type is
one per document and carries a confidentiality default; a keyword is many
per document and carries nothing (findings A15).

### D3: `direction` is an enum on `informatieobject`

`informatieobject.direction`: `{"type": "string", "enum": ["incoming",
"outgoing", "internal"], "title": "Direction", "facetable": true}`,
optional, default `internal`. The metadata dialog offers it beside the type.
Zaaksysteem's Richting and gzac's `richting` map onto it one to one.

### D4: Generate document is a header action running `DossiqMergeTemplateNode`, with `open-modal` as the interim

Target: `CaseDetail.headerActions` entry `generate-document`, `type:
run-action`, `label: Generate document`, `icon: FileDocumentPlusOutline`,
`node: DossiqMergeTemplateNode`, `subject: @objectId`, `config: {"templateSlug":
"@pick:template"}`. The template picker lists what `TemplateController#index`
returns. `MergeTemplateHandler` gains one branch: when `targetField` is
absent, it stores the rendered result as an `informatieobject` (`title` from
the template name, `status: draft`, `direction: outgoing`, `auteur` the
signed-in user, `informatieobjecttype` from the template's `documentType`)
and links it with a `zaakinformatieobject` to the case. The action's
`successMessage` says the document was added and the Documents tab
refreshes.

`actionsDispatcher.js` does not dispatch `run-action`. Until nextcloud-vue
adds it, the header action is `type: open-modal`, `modal:
BeschikkingComposerDialog`, `props: {"caseId": "@objectId"}`. The dialog
already picks a template and composes from case data; the additive handler
branch is what makes its result an `informatieobject` on the case.

Alternative rejected: `type: api-call` to a new dossiq route. A route that
runs a Flow node by name is engine work and a second way to run an action
next to the Flow runtime.

### D5: the Files tab stays

The `files` integration leaf keeps loose attachments and Nextcloud shares.
The Documents tab is the ZGW dossier. Removing Files would drop the share
and comment surface the leaf gives for free (`case-share-via-shares-leaf`).

## Risks / Trade-offs

- **Two renderings of one list.** `DossierTab` and the future `object-list`
  must agree on columns; the e2e asserts the six headers so the swap cannot
  drop one.
- **A handler branch on a Flow node.** The interim dialog and the future
  action both reach `MergeTemplateHandler`; the unit test covers both
  `targetField` present and absent.
- **Keyword sprawl.** Free text tags with no vocabulary. The facet shows
  what is in use; a controlled list is a later change on
  `informatieobjecttype`.
- **Direction defaults.** Back-filled documents (REQ-ZAK-010) get
  `internal`; the column reads Internal until someone edits.

## Migration Plan

1. Add `keywords` and `direction` to the schema, bump to 1.1.0; the repair
   step at `occ upgrade` re-imports the register (existing rows validate,
   both fields optional).
2. Add the widget, tab, filter and header action to `src/manifest.json`.
3. Add the handler branch and its unit test.
4. Swap `custom` for `object-list` and `open-modal` for `run-action` when
   the two nextcloud-vue items land; the e2e is written against labels and
   ids, not widget type.

## Open Questions

- Does the template's `documentType` exist on every library template, or
  does the handler fall back to the first `informatieobjecttype` of category
  `uitgaand`?
- Should `direction` be required on upload once every competitor mapping
  lands, or stay optional with the default?
