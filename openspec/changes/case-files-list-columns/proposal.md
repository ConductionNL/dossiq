# Case files list columns

## Why

Row 4.8 (configurable document list columns). nextcloud-vue shipped `files-browser-columns` (all tasks ticked): `CnFilesBrowser` takes a `columns` prop (a node attribute, a DAV property, or a `source: 'row'` value from the host) and a `rowData` prop that fetches the host's per-file data once per folder. dossiq's Files tab on the case (`src/manifest.json` CaseDetail, the `files` integration widget) passes neither, so the list shows name, size and modified only. Board DqZaakDocumenten draws the document type, direction, sender and the review verdict next to the name.

## What changes

- The case Files widget declares the columns DqZaakDocumenten draws and fills the `row` columns from dossiq's document projection (`document-projection` spec) in one request per folder.
- A case type can hide or reorder those columns through the existing case-type view config; the widget never adds a column the page did not declare.

## Impact

- `src/manifest.json` (CaseDetail files widget `props.columns`), a `rowData` provider in `src/services/documentRowData.js` reading the projection endpoint.
- Spec: one requirement added to `document-zaakdossier`.
