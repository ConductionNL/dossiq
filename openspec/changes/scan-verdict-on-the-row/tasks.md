# Tasks: scan-verdict-on-the-row

Tier: V1. Kind: config plus one reader. Row 4.19. Waits on
`documents-on-the-case`.

- [ ] 1.1 A verdict reader in `lib/Service/Documents/` behind
  `IAppManager::isInstalled('files_antivirus')`, three states (D-1).
  - unit: clean, infected, not scanned, app absent
  - `@spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md`
- [ ] 1.2 Formatter and the Scan column on the Files tab; the hash and the
  verdict in the properties dialog.
- [ ] 2.1 `tests/e2e/scan-verdict.spec.ts`; `openspec validate
  scan-verdict-on-the-row --strict`.
