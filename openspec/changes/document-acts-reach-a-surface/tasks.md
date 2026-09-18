# Tasks: document-acts-reach-a-surface

Tier: V1. Kind: config, plus one orphan check. Rows 4.3 and 4.20.
No new backend: every endpoint and every component this wires already
ships and is unit covered.

## 1. The version history reaches a row

- [x] 1.1 `src/manifest.json`, widget `case-files`, `props.rowActions`: add
  `versions`, `type: open-modal`, `target: VersionHistoryPanel`, icon
  `History`, label Versions. CnFilesBrowser merges the node's `fileId`,
  `fileName` and `path` into an open-modal action's props, so the panel is
  handed the file it was clicked on.
  - `@spec openspec/changes/document-acts-reach-a-surface/specs/document-zaakdossier/spec.md`
  - `props: { open: true }` is passed with it, the way every other
    open-modal on this page does: CnAppRoot mounts a registry modal with the
    action's props verbatim and the panel renders on `open`.
- [x] 1.2 `src/modals/VersionHistoryPanel.vue`: read the file from
  `fileId` when one arrives, keeping the `row.informatieobject` path for
  the callers that still pass a row. A panel that receives neither says so
  and offers Show in Files, rather than rendering an empty list.
  - unit: a `fileId` prop lists that file's versions; neither prop renders
    the refusal and not an empty list
  - both land in `tests/vitest/versionHistoryPanel.spec.js`, plus a third
    arm pinning the precedence when both props arrive.

## 2. The bulk acts reach the selection

- [x] 2.1 `src/manifest.json`, widget `case-files`: mark final and change
  confidentiality are `rowActions`, each `type: open-modal` onto
  `BulkDocumentActionDialog` with its `mode`.
  - **MEASURED, and it changed the task.** `bulkActions` is NOT declared,
    because CnFilesBrowser has no selection bar to declare them over. Its
    own header note says why: the Files app's list, its selection bar and
    its inline rename are a Vue app bound to the Files router and its
    stores, and none of the three can be mounted on a case page. The
    component reads `rowActions`, `newActions` and `linkedItems` and
    nothing else. A `bulkActions` prop declared on it would have been read
    by nothing, which is the same silence that left both of these dialogs
    dark for five days and is the thing this change exists to end.
  - The third mode, download as zip, has no caller here on purpose: the
    whole case file as one download is the CaseDetail Export dossier header
    action of task 3.1, and two buttons for one zip is the worse answer.
  - `BulkDocumentActionDialog` gained the `fileId` path for it. The case's
    dossier listing is the one endpoint carrying every record beside its
    `fileId`, which is how DocumentMetadataDialog already finds the record
    for the same node. An empty id list now REFUSES: both bulk endpoints
    answer 200 with an empty per-item list, which rendered as "0 of 0
    document(s) updated", a success sentence over an act that never had a
    document.
  - REQ-ZAK-021 is reworded to what ships, and says in the requirement why
    a selection is not part of it. Restoring the multi-file act is a
    nextcloud-vue change (a selection on CnFilesBrowser), not a dossiq one.
- [x] 2.2 `src/registry.js`: rewrite the notes on `VersionHistoryPanel`
  and `BulkDocumentActionDialog`. Both name the Documents tab as their
  parent and that tab no longer exists, which is what let them go dark
  unnoticed.

## 3. The dossier export reaches the case

- [x] 3.1 `src/manifest.json`, page CaseDetail, `headerActions`: add
  `export-dossier`, `type: api-call`, label Export dossier, icon
  `FolderZipOutline`, `download: true`.
  - **THE ROUTE IN THIS TASK WAS THE WRONG ONE.**
    `GET /api/dossier/{caseId}/export` is `DossierExportController` and it
    answers a JSON export PLAN for a beroep dossier, not bytes. An action
    declared on it with `download: true` would have handed the reader a
    file called `dossier.zip` holding JSON. The endpoint that answers what
    REQ-ZAK-022 asks for is
    `POST /api/cases/{caseId}/dossier/zip` (`ZaakdossierDownloadController`),
    which returns a `DataDownloadResponse` of a zip with `manifest.csv` at
    its root and one sub-folder per informatieobjecttype
    (`ZipManifestBuilder`). That is the route the action uses.
  - `FolderZipOutline` was not in `src/icons.js` and is registered now; an
    unregistered name renders no icon at all rather than a fallback glyph
    (hydra gate-60).
- [x] 3.2 `lib/Controller/DossierExportController.php`: confirm the reader
  guard and the refusal shape, and add the guard if the read is open. An
  export is the whole case file in one download, so it refuses what the
  case page would refuse.
  - unit: a reader who may not read the case gets 403 and no bytes
  - **MEASURED: both guards were already there and neither had a test.**
    `DossierExportController::export()` and
    `ZaakdossierDownloadController::downloadZip()` each call
    `CaseAccessGuard::hasCaseReadAccess` before any read. The zip route had
    `ZaakdossierDownloadControllerGuardTest`; the plan route had nothing at
    all, so `DossierExportControllerGuardTest` is new. Three arms: refused
    before the plan is built, allowed for a reader who may read the case
    (without which a guard refusing everything would pass), and a blank
    case id refused before the guard is even asked.

## 4. A dark registry entry says so

- [x] 4.1 `tests/vitest/registryOrphans.spec.js`: every `kind: 'modal'`
  entry in `src/registry.js` is named by at least one manifest action, or
  carries an explicit `_orphanReason`. Assert the count of checked entries
  as well, so a loop that matched nothing cannot pass.
  - this is the test that would have caught both dialogs on the branch
    that orphans their parent
  - **IT FOUND A THIRD ONE ON ITS FIRST RUN.**
    `CaseLifecycleActionDialog` is registered, and its note claimed four
    CaseDetail header actions opened it. None do: the page carries
    `case-lifecycle-menu` onto `CaseLifecycleMenuDialog`, which offers the
    same four gestures and POSTs them itself. It carries an
    `_orphanReason` now rather than a deletion, because choosing between
    the two surfaces is its own change; what the reason buys is that the
    debt is written where the next reader of the registry will see it.
  - The check reads `target` values only. A name that appears in a `_note`
    does not count, which is exactly how the lifecycle dialog read as
    wired while nothing opened it.
  - Mutation check: dropping the `versions` row action from the manifest
    reddens `expect(orphans).toEqual([])` with
    `expected [ 'VersionHistoryPanel' ] to deeply equal []`, and the
    named-dialogs arm with it. Restored, green.

## 5. Verification

- [x] 5.1 `tests/e2e/document-acts.spec.ts`: open a case with a file,
  open Versions from the row menu and read the panel; mark a file final
  from its row; press Export dossier and assert a download; and an
  anonymous caller gets a status and no zip bytes from the export
  endpoint. Written and tagged, not run: there is no Playwright run on
  this box.
- [x] 5.2 `openspec validate document-acts-reach-a-surface --strict`.
- [x] 5.3 Mutation check on REQ-ZAK-020: forcing the refusal branch off
  (`v-if="false"` on the empty-content that carries it) reddens
  `expect(wrapper.text()).toContain('No file to read versions of')` with
  `expected 'Version history' to contain 'No file to read versions of'`.
  The rendered sentence is asserted BEFORE the computed flag on purpose, so
  a mutation that keeps the flag and drops the sentence still reddens on
  what a reader would have seen. Restored, green.
