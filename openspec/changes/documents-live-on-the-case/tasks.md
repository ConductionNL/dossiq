# Tasks: documents-live-on-the-case

Tier: V1. Kind: feature. Every checkbox below is one implementation task; the
criteria under a task are plain bullets.

## 1. The projection service and the case-type default

- [ ] 1.1 `lib/Settings/register.d/70-document-zaakdossier.json`: add the optional
  `defaultInformatieobjecttype` reference on the `caseType` schema (fragment
  that owns caseType), bump its `version`.
  - `@spec openspec/specs/document-projection/spec.md`
  - `tests/schemas` round-trips the property
- [ ] 1.2 `lib/Service/Zaakdossier/DocumentProjectionService.php`: `projectNode`,
  `retireNode`, `rehomeNode`, `homeDocument`, `resolveCaseForNode`, with the
  defaults of design D3 and the lookup-by-`fileId` of D2.
  - `tests/Unit/Service/Zaakdossier/DocumentProjectionServiceTest.php`: create
    with defaults; second write refreshes and does not duplicate; rename follows
    and a hand-set title survives; draft delete removes, final delete archives;
    move between cases re-homes; folder and out-of-register nodes are ignored
- [ ] 1.3 `lib/Listener/CaseFolderNodeListener.php` on `NodeCreatedEvent`,
  `NodeWrittenEvent`, `NodeRenamedEvent`, `NodeDeletedEvent`, registered in
  `lib/AppInfo/Registrar/ObjectListenerRegistrar.php` (registration only, ADR-106).
  - `tests/Unit/Listener/CaseFolderNodeListenerTest.php`: dispatches to the
    service per event, ignores folders, never throws out of `handle()`

## 2. The API paths

- [ ] 2.1 `lib/Controller/ZrcController.php`: after a created
  `zaakinformatieobject`, call `homeDocument`; refuse the join with 422 when the
  case's folder cannot be resolved.
  - `tests/Unit/Controller/ZrcControllerZaakinformatieobjectTest.php`: first join
    moves, second join does not, no folder refuses
- [ ] 2.2 `lib/Service/Zaakdossier/DossierUploadHandler.php` and
  `src/modals/DocumentMetadataDialog.vue`: attach the upload to the case and
  save the metadata onto the record found by `fileId` (design D8).
  - unit test on the handler; `tests/vitest/documentMetadataDialog.spec.js`
    asserts the case files endpoint and the follow-up save

## 3. The migration

- [ ] 3.1 `lib/Repair/MoveDocumentsIntoCaseFolders.php`, registered under
  `repair-steps/post-migration` in `appinfo/info.xml`, gated on
  `documents_on_case_migrated` (design D7).
  - `tests/Unit/Repair/MoveDocumentsIntoCaseFoldersTest.php`: moves once,
    refreshes `fileId` when changed, skips files already home, names a missing
    node, second run moves nothing

## 4. The Files tab

- [ ] 4.1 @conduction/nextcloud-vue `CnFilesBrowser`: `rowActions` and
  `linkedItems` props, forwarded by `CnFilesTab`; docs and jest; a release.
  - `tests/components/CnFilesBrowser.spec.js`: host actions render after the
    registered ones; linked rows render after nodes with open and download only
- [ ] 4.2 dossiq manifest `case-files` widget and `src/registry.js`: the
  Document properties action opening `DocumentMetadataDialog` on the row's
  file id; the linked documents of the case fetched from its joins.
  - `tests/vitest/caseFilesTab.spec.js`: the action and the linked list are
    declared; `npm run check:manifest`
- [ ] 4.3 `openspec/specs/case-dashboard-view/spec.md` and
  `document-zaakdossier/spec.md`: land the deltas on archive.

## 5. End to end

- [ ] 5.1 `tests/e2e/case-documents-on-the-case.spec.ts`: a drop is a document
  with defaults; Document properties edits the record; a document joined from
  another case is a linked row; an API-created document moves on its first join;
  the e2e residue is purged through the case purge helper.
  - `@spec openspec/specs/document-projection/spec.md`
  - `@spec openspec/specs/case-dashboard-view/spec.md#a-row-edits-its-documents-properties`
