# Tasks: case-last-activity-at

### Task 1: Work on a case stamps its last activity
- **spec_ref**: `openspec/changes/case-last-activity-at/specs/case-last-activity-at/spec.md#requirement-work-on-a-case-stamps-its-last-activity`
- **files**: `lib/Service/Activity/CaseActivityStamp.php`, `lib/Listener/CaseLastActivityListener.php`, `lib/AppInfo/Registrar/CaseActivityListenerRegistrar.php`, `lib/Settings/dossiq_register.json`
- [x] Implement
- [x] Test: `tests/Unit/Listener/CaseLastActivityListenerTest.php`, `tests/Unit/Service/Activity/CaseActivityStampTest.php`, `tests/Unit/AppInfo/CaseActivityListenerRegistrarTest.php`

### Task 2: Stalled lists read the last activity
- **spec_ref**: `openspec/changes/case-last-activity-at/specs/case-last-activity-at/spec.md#requirement-stalled-lists-read-the-last-activity`
- **files**: `lib/Settings/dossiq_register.json`, `src/manifest.json`, `src/menu-layout.simple.json`
- [x] Implement
- [x] Test: `tests/vitest/caseLastActivityAt.spec.js`
