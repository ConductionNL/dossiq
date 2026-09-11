---
kind: config
depends_on: []
---

# Proposal: documents-on-the-case

Round 2 competitor analysis, rows A14, A15 and A16 of
`concurrentie-analyse/procest/_round2/compare/placement.md`. Rung 3 on the
placement ladder for A14 (a tab on an existing widget over an existing
schema), rung 1 for A15 (a property on an existing schema, bound to the form
and the list) and rung 2 for A16 (a header action over an existing Flow
node). One noun: the case file.

## Why

You cannot see the documents of a case, and you cannot add one. The schemas
`informatieobject`, `zaakinformatieobject` and `informatieobjecttype`
(`lib/Settings/register.d/70-document-zaakdossier.json`) exist. `DossierTab.vue`
groups the dossier by type, takes a dropped file through
`DocumentMetadataDialog.vue` and shows `VersionHistoryPanel.vue` per row. It
is registered in `src/customComponents.js` and linked from no page. On
`CaseDetail` the Files tab of `case-panels` is the `files` integration leaf,
which renders an empty slot (`dossiq-defect-triage.md` #2) and knows nothing
of type, status or confidentiality (`_round2/dossiq-baseline/case-detail-anatomy.md`
tab 2). The spec `document-zaakdossier` says shipped; the surface is dead.

A document carries a type, not a tag. `informatieobjecttype` is a catalogue
entry with a default confidentiality; nothing lets you label a document
"bezwaar" or "bouwtekening" and filter on it (findings A15, M1 4.9).

A letter cannot be generated from the case. `DossiqMergeTemplateNode`,
`MergeTemplateHandler`, `TemplateController` and
`BeschikkingComposerDialog.vue` exist; the Store shows templates without an
action and no page offers the node (findings A16, M3 Document generation).

Every competitor lists the documents on the case with type, status, direction
and date, offers a drop zone and file versions (opencase
`CaseDetail-Documents`, `CaseDetail-Files`; gzac `CaseDefinition-ZGW`;
zaaksysteem `Case-Documenten`). Two of three tag documents (gzac
`Trefwoorden`, zaaksysteem labels). All three generate a document from a
template on the case (opencase `DocumentCreate`; gzac SmartDocuments;
zaaksysteem `Acties: document from template`).

## What changes

- **A14, Documents tab.** `case-panels` on `CaseDetail` gains a tab
  Documents. Target: widget `case-documents`, type `object-list` over
  `zaakinformatieobject` filtered on `case = @objectId`, columns title, type,
  status, direction, date and author read through the `informatieobject`
  reference. Interim, until nextcloud-vue renders a `$ref` column by a label
  field (placement section 3, A35 and A36): a `custom` widget rendering
  `DossierTab`, whose drop zone, metadata dialog and version panel already
  do the work. The Files tab stays for loose files.
- **A15, keywords.** `informatieobject.keywords`, an array of strings with a
  tags widget in `DocumentMetadataDialog` and the data form, and a keyword
  filter on the Documents tab. English name per decisions D13; the label
  reads Trefwoorden in `nl.json`.
- **A16, Generate document.** A header action Generate document on
  `CaseDetail`, type `run-action`, that runs `DossiqMergeTemplateNode` with
  the case as subject and a template picked from the library. The rendered
  result is stored as an `informatieobject` linked through
  `zaakinformatieobject`, so it lands on the Documents tab.
- **Direction.** `informatieobject.direction`, an enum incoming, outgoing,
  internal, because the column every competitor shows has no property yet.

## Capabilities

- `document-zaakdossier`: ADDED REQ-ZAK-011 (Documents tab), REQ-ZAK-012
  (keywords), REQ-ZAK-013 (direction).
- `template-library`: ADDED REQ-005 (a template is offered on the case).
- `beschikking-generatie`: ADDED REQ-BES-012 (Generate document action).

## Out of scope

- Moving the template library to filinq (placement flag: it moves later, the
  action stays here).
- Folders inside the dossier (zaaksysteem). The type grouping is the folder.
- Bulk operations and ZIP export (REQ-ZAK-008 already covers them).
- The `files` leaf itself (triage #2 fixes the leaf).

## Impact

Kind config: two schema properties, one manifest widget, one manifest tab,
one manifest header action and one manifest filter. `MergeTemplateHandler`
learns to write an `informatieobject` when `targetField` is absent; that is
the only PHP change and it is additive.
