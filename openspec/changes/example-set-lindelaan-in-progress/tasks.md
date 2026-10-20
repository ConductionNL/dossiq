# Tasks: example-set-lindelaan-in-progress

### Task 1: The example Woo case is in progress with its contact moments
- **spec_ref**: `openspec/changes/example-set-lindelaan-in-progress/specs/example-set-lindelaan-in-progress/spec.md#requirement-the-example-woo-case-is-in-progress-with-its-contact-moments`
- **files**: `lib/Settings/example-sets/zuiddrecht.json`
- [x] Implement: in-progress status, no publication state, four contact moments
- [x] Test: `tests/Unit/Settings/ZuiddrechtWooLindelaanInProgressTest.php`

### Task 2: Seven documents, four assessed, no decision yet
- **spec_ref**: `openspec/changes/example-set-lindelaan-in-progress/specs/example-set-lindelaan-in-progress/spec.md#requirement-the-example-woo-case-holds-seven-documents-four-assessed-and-no-decision-yet`
- **files**: `lib/Settings/example-sets/zuiddrecht.json`, `lib/Settings/example-sets/zuiddrecht/woo-lindelaan/`
- [x] Implement: informatieobjecten with zaakinformatieobject rows, three new fictional files, the decision and notice of default dropped
- [x] Test: `tests/Unit/Settings/ZuiddrechtWooLindelaanInProgressTest.php`

### Task 3: The premature notice of default sits on Kapvergunning Molenweg 4
- **spec_ref**: `openspec/changes/example-set-lindelaan-in-progress/specs/example-set-lindelaan-in-progress/spec.md#requirement-the-premature-notice-of-default-sits-on-kapvergunning-molenweg-4`
- **files**: `lib/Settings/example-sets/zuiddrecht.json`
- [x] Implement: the case, its running term, the premature notice
- [x] Test: `tests/Unit/Settings/ZuiddrechtPrematureNoticeOfDefaultTest.php`

### Task 4: A closed Woo case is ready to archive
- **spec_ref**: `openspec/changes/example-set-lindelaan-in-progress/specs/example-set-lindelaan-in-progress/spec.md#requirement-a-closed-woo-case-is-ready-to-archive`
- **files**: `lib/Settings/example-sets/zuiddrecht.json`
- [x] Implement: ending act, retention facts and archive status on `woo-zaaksysteem`, its decision on the case through the change
- [x] Test: `tests/Unit/Settings/ZuiddrechtWooReadyToArchiveTest.php`

### Task 5: The in-progress case carries its advice, its status dates and its archive status
- **spec_ref**: `openspec/changes/example-set-lindelaan-in-progress/specs/example-set-lindelaan-in-progress/spec.md#requirement-the-in-progress-case-carries-its-advice-its-status-dates-and-its-archive-status`
- **files**: `lib/Settings/example-sets/zuiddrecht.json`
- [x] Implement: two advice requests, archive status
- [x] Test: `tests/Unit/Settings/ZuiddrechtWooLindelaanInProgressTest.php`

### Task 6: The in-progress case carries its tasks
- **spec_ref**: `openspec/changes/example-set-lindelaan-in-progress/specs/example-set-lindelaan-in-progress/spec.md#requirement-the-in-progress-case-carries-its-tasks`
- **files**: `lib/Settings/example-sets/zuiddrecht.json`
- [x] Implement: four tasks on the case
- [x] Test: `tests/Unit/Settings/ZuiddrechtWooLindelaanInProgressTest.php`
