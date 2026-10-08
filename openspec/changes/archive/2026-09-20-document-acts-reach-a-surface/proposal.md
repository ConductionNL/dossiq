---
kind: config
depends_on: [documents-on-the-case]
---

# Proposal: document-acts-reach-a-surface

Competitor gap scan of 2026-09-18, pack b, rows 4.3 "File versions with
view, download, restore" and 4.20 "Archive export of closed cases". Both
scored partial. Owner dossiq. Size S.

## Why

Three acts on the case file are built, tested and reachable by nobody.

`src/modals/VersionHistoryPanel.vue` reads the Nextcloud Files versions of
one dossier document and restores one. `src/modals/BulkDocumentActionDialog.vue`
marks a selection final, changes its confidentiality or downloads it as a
zip. Both are imported and registered in `src/registry.js`, and both are
named in `src/manifest.json` zero times. Their registry notes still say the
Documents tab opens them through `rowActions` and `bulkActions`. That tab
was retired on 2026-09-13 and replaced by the `case-files` leaf, which
declares one row action, Document properties, and one New entry, Request a
file. The dialogs went dark with the tab and nothing said so.

`GET /api/dossier/{caseId}/export` has the same shape of problem from the
other end. `lib/Service/Zaakdossier/DossierZipExporter.php` and
`ZipManifestBuilder.php` build the zip and its manifest,
`appinfo/routes.php` routes `dossierExport#export`, and no line of `src/`
calls it. CaseDetail carries twenty header actions and none of them exports
the dossier.

Versions also cannot come from the platform on this page. CnFilesBrowser
drops `details` and `sidebar` from a row's menu, because on Nextcloud 34
the Files details sidebar is a store bound to the Files app's own router.
So a handler who wants the versions of a case document leaves for the Files
app first. The panel that would have answered on the page is sitting in the
registry.

## What changes

- `case-files` gains a Versions row action, opening `VersionHistoryPanel`
  the way Document properties opens `DocumentMetadataDialog`.
- `case-files` gains the three bulk acts over a selection, opening
  `BulkDocumentActionDialog` in its three modes.
- CaseDetail gains an Export dossier header action over the endpoint that
  already exists, offered only on a case a reader may read.
- A registry entry that no page resolves fails the manifest test suite, so
  the next dialog to go dark says so on the branch that orphans it.

## Ownership

dossiq owns all three, because all three are already dossiq code. Nothing
new is asked of a sibling. Versions themselves stay Nextcloud's: the panel
reads the Files versions API and restores through it, and dossiq stores no
version chain of its own. The e-Depot, SIP and MDTO half of row 4.20 stays
openregister's under `archief-edepot-handover`, which this change does not
touch.

## ADRs

- Company ADR-022: the version data is read from the platform, not wrapped.
- Company ADR-062: an act on a record is a declared manifest action.

## Capabilities

- Modified: `document-zaakdossier`: the version history, the bulk acts and
  the dossier export each reach a surface.

## Impact

`src/manifest.json` (the `case-files` props and the CaseDetail header
actions); `src/registry.js` (the three notes that describe a retired
parent); `tests/vitest/` (the orphan check).
