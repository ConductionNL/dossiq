# Tasks: document-acts-reach-a-surface

Tier: V1. Kind: config, plus one orphan check. Rows 4.3 and 4.20.
No new backend: every endpoint and every component this wires already
ships and is unit covered.

## 1. The version history reaches a row

- [ ] 1.1 `src/manifest.json`, widget `case-files`, `props.rowActions`: add
  `versions`, `type: open-modal`, `target: VersionHistoryPanel`, icon
  `History`, label Versions. CnFilesBrowser merges the node's `fileId`,
  `fileName` and `path` into an open-modal action's props, so the panel is
  handed the file it was clicked on.
  - `@spec openspec/changes/document-acts-reach-a-surface/specs/document-zaakdossier/spec.md`
- [ ] 1.2 `src/modals/VersionHistoryPanel.vue`: read the file from
  `fileId` when one arrives, keeping the `row.informatieobject` path for
  the callers that still pass a row. A panel that receives neither says so
  and offers Show in Files, rather than rendering an empty list.
  - unit: a `fileId` prop lists that file's versions; neither prop renders
    the refusal and not an empty list

## 2. The bulk acts reach the selection

- [ ] 2.1 `src/manifest.json`, widget `case-files`: declare `bulkActions`
  for mark final, change confidentiality and download as zip, each
  `type: open-modal` onto `BulkDocumentActionDialog` with its `mode`.
- [ ] 2.2 `src/registry.js`: rewrite the notes on `VersionHistoryPanel`
  and `BulkDocumentActionDialog`. Both name the Documents tab as their
  parent and that tab no longer exists, which is what let them go dark
  unnoticed.

## 3. The dossier export reaches the case

- [ ] 3.1 `src/manifest.json`, page CaseDetail, `headerActions`: add
  `export-dossier`, `type: api-call`, GET
  `/apps/dossiq/api/dossier/@objectId/export`, label Export dossier, icon
  `FolderZipOutline`. The response is a file, so the action downloads it
  rather than showing a success message.
  - `@spec openspec/changes/document-acts-reach-a-surface/specs/document-zaakdossier/spec.md`
- [ ] 3.2 `lib/Controller/DossierExportController.php`: confirm the reader
  guard and the refusal shape, and add the guard if the read is open. An
  export is the whole case file in one download, so it refuses what the
  case page would refuse.
  - unit: a reader who may not read the case gets 403 and no bytes

## 4. A dark registry entry says so

- [ ] 4.1 `tests/vitest/registryOrphans.spec.js`: every `kind: 'modal'`
  entry in `src/registry.js` is named by at least one manifest action, or
  carries an explicit `_orphanReason`. Assert the count of checked entries
  as well, so a loop that matched nothing cannot pass.
  - this is the test that would have caught both dialogs on the branch
    that retired their parent

## 5. Verification

- [ ] 5.1 `tests/e2e/document-acts.spec.ts`: open a case with a file,
  open Versions from the row menu and read the list; select two files and
  mark them final; press Export dossier and assert a download.
- [ ] 5.2 `openspec validate document-acts-reach-a-surface --strict`.
