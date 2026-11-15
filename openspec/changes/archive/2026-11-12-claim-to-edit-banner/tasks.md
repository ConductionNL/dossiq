# Tasks: claim-to-edit-banner

### Task 1: A reader who is not the handler can claim the case to edit it
- **spec_ref**: `openspec/changes/claim-to-edit-banner/specs/case-management/spec.md#requirement-a-reader-who-is-not-the-handler-can-claim-the-case-to-edit-it`
- **files**: `src/manifest.json`, `l10n/en.json`, `l10n/nl.json`, `tests/schemas/app-manifest-v2.schema.json`
- [x] Implement: `permissions` on CaseDetail, `case-claim-banner` in the banner row, en/nl strings
- [x] Test: `tests/vitest/caseClaimBanner.spec.js`
