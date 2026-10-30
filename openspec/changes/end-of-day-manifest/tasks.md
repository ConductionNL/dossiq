# Tasks: end-of-day-manifest

### Task 1: The day lists are worked out on the server
- **spec_ref**: `openspec/changes/end-of-day-manifest/specs/end-of-day-manifest/spec.md#requirement-the-day-lists-are-worked-out-on-the-server`
- **files**: `lib/Service/Queue/EndOfDayService.php`, `lib/Controller/PersonalQueueController.php`
- [x] Implement: open, recorded, tomorrow, figures, unavailable
- [x] Test: `tests/Unit/Service/Queue/EndOfDayServiceTest.php`, `tests/Unit/Controller/PersonalQueueControllerTest.php`

### Task 2: The page is manifest config and keeps what the screen held
- **spec_ref**: `openspec/changes/end-of-day-manifest/specs/end-of-day-manifest/spec.md#requirement-the-page-is-manifest-config-and-keeps-what-the-screen-held`
- **files**: `src/manifest.json`, `src/registry.js`, `src/services/personalQueueApi.js`, `src/utils/personalQueueHelpers.js`, `l10n/*`
- [x] Implement: the dashboard page; the custom view and its helpers removed
- [x] Test: `tests/vitest/endOfDayScreen.spec.js`, `tests/e2e/one-personal-queue.spec.ts`

### Task 3: Each row names its case
- **spec_ref**: `openspec/changes/end-of-day-manifest/specs/end-of-day-manifest/spec.md#requirement-each-row-names-its-case`
- **files**: `lib/Service/Queue/EndOfDayRowMeta.php`, `lib/Service/Queue/EndOfDayService.php`, `src/manifest.json`, `l10n/*`
- [x] Implement: the open row's `meta` and the recorded row's `recordedMeta`; the two lists template them
- [x] Test: `tests/Unit/Service/Queue/EndOfDayRowMetaTest.php`, `tests/Unit/Service/Queue/EndOfDayServiceTest.php`, `tests/vitest/endOfDayScreen.spec.js`
