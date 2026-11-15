# Tasks: access-purpose-handler

### Task 1: The case's own handler opens it without the dialog
- **spec_ref**: `openspec/changes/access-purpose-handler/specs/case-access-purpose/spec.md#requirement-the-cases-own-handler-opens-it-without-the-dialog`
- **files**: `lib/Service/CaseAccessPurposeService.php`, `lib/Service/AccessPurposeRules.php`, `lib/Service/People/CaseRoleHolder.php`, `lib/Controller/CaseAccessPurposeController.php`, `appinfo/routes.php`, `src/manifest.json`
- [x] Implement: `GET /api/cases/{id}/access-purpose`, the configured skip roles and purpose, the page's `checkUrl` (nextcloud-vue feat/access-purpose-check-and-option-hint)
- [x] Test: `tests/Unit/Service/CaseAccessPurposeServiceTest.php`, `tests/Unit/Controller/CaseAccessPurposeControllerTest.php`, `tests/Unit/Service/People/CaseRoleHolderTest.php`

### Task 2: The dialog says why it shows
- **spec_ref**: `openspec/changes/access-purpose-handler/specs/case-access-purpose/spec.md#requirement-the-dialog-says-why-it-shows`
- **files**: `src/manifest.json`, `l10n/*`
- [x] Implement: the intro sentence

### Task 3: Handling the case makes you its handler
- **spec_ref**: `openspec/changes/access-purpose-handler/specs/case-access-purpose/spec.md#requirement-handling-the-case-makes-you-its-handler`
- **files**: `lib/Service/CaseAccessPurposeService.php`, `lib/Service/People/CaseRoleHolder.php`, `src/manifest.json`, `src/menu-layout.simple.json`, `l10n/*`
- [x] Implement: the configured purpose to role binding, the second audit entry, the option hint, the history label
- [x] Test: `tests/Unit/Service/CaseAccessPurposeServiceTest.php`

### Task 4: Document the dialog
- **files**: `docs/user-guide/user/10-open-a-case-with-special-personal-data.md`
- [x] Write: when the dialog shows, why, what each purpose does, Behandeling makes you handler
