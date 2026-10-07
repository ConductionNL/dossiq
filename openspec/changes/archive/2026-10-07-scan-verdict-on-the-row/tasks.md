# Tasks: scan-verdict-on-the-row

Tier: V1. Kind: config plus one reader. Row 4.19. Waits on
`documents-on-the-case`.

- [x] 1.1 A verdict reader in `lib/Service/Documents/ScanVerdictReader.php`
  behind `IAppManager::isInstalled('files_antivirus')`, three states (D-1).
  - unit: clean, infected, not scanned, app absent, an unknown status, a
    missing table, a clean row with no time, and an impossible file id
    (`tests/Unit/Service/Documents/ScanVerdictReaderTest.php`, 8 cases).
    Mutation-checked: reading an unknown status as clean reddens exactly the
    assertion that names it, `expected 'not-scanned' to be 'clean'`.
  - `GET /api/files/{fileId}/scan` on `ScanVerdictController`, which resolves
    the node in the CALLER'S own folder, so a caller who cannot see the file
    gets a 404 rather than a verdict.
  - `@spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md`
- [x] 1.2 Formatter and the Scan column on the Files tab; the hash and the
  verdict in the properties dialog. The formatter is `scanVerdict` in
  `src/services/formatters.js` over `src/services/scanVerdict.js`, which is
  where this repo keeps its formatter registry rather than `src/formatters/`.
  The column is inert until nextcloud-vue ships `files-browser-columns`, the
  same as Sender and Recipients beside it, so the verdict reads on the
  document properties dialog today. Mutation-checked: falling back to clean
  in `stateOf()` reddens three assertions, one of them
  `expected 'Clean' to be 'Not scanned'`.
- [x] 2.1 `tests/e2e/scan-verdict.spec.ts`; `openspec validate
  scan-verdict-on-the-row --strict` passes. The clean scenario carries an
  `@e2e exclude` in the spec and is covered by the unit test over a stubbed
  reader; the e2e drives the no-scanner case and the refusal.
