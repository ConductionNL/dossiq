# Tasks: a-recorded-decision-is-listed-on-its-case

- [x] 1.1 `CaseDecisionLink::link` adds a decision to the case's `decisions`, once.
- [x] 1.2 Assembling a decision from the assessments calls it, through `PublicationReadiness::markCaseReady`.
  - `tests/Unit/Service/WOODecisionServiceTest.php::testTheRecordedDecisionIsListedOnItsCase`
- [x] 1.3 The uuid-answering register double mints uuids on create, so the real-schema test can hold the case's `decisions` to its uuid format.
