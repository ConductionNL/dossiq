# Tasks: seeded-case-types-are-published

- [x] 1.1 Add `"isDraft": false` to the 13 shipped case types that lacked it.
  - `tests/Unit/Repair/PublishSeededCaseTypesTest.php::testEveryShippedCaseTypeDeclaresIsDraft`
- [x] 1.2 `lib/Repair/PublishSeededCaseTypes.php`: publish those 13 once, record the run.
  - `tests/Unit/Repair/PublishSeededCaseTypesTest.php::testTheSeededDraftsArePublishedAndNothingElse`
  - `tests/Unit/Repair/PublishSeededCaseTypesTest.php::testARecordedRunDoesNotRunAgain`
  - `tests/Unit/Repair/PublishSeededCaseTypesTest.php::testAFailedWriteIsTriedAgainNextTime`
- [x] 1.3 Register the step in `appinfo/info.xml` (`<post-migration>` and `<install>`), after the case type seeds.
