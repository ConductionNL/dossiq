# Tasks: lifecycle-gesture-saves-the-case-as-stored

- [x] 1.1 `CaseLifecycleService::journal()` takes the case id and the gesture's own
  changes, reads the case again and merges the changes before appending the entry.
  - `tests/Unit/Service/CaseLifecycleServiceTest.php::testPauseOnATermSavesTheCaseTheMirrorWrote`
  - `tests/Unit/Service/CaseLifecycleServiceTest.php::testExtendOnATermSavesTheCaseTheMirrorWrote`
- [x] 1.2 `CaseLifecycleController` answers an unexpected failure with
  `code: change_failed`.
  - `tests/Unit/Controller/CaseLifecycleControllerTest.php::testAnUnexpectedFailureWithholdsItsDetail`
- [ ] 2.1 Frontend lane: map `change_failed` in `src/utils/caseLifecycleHelpers.js`
  to `t('The case could not be changed.')`.
