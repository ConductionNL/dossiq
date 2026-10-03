# Tasks: woo-redaction-reads-filinqs-detector-report

- [x] 1.1 `FilinqRedactionClient` reports filinq's detector refusal as `detection_unavailable` with reason and backend (D1).
  - unit: `FilinqRedactionClientTest::testARunWithNoLiveDetectorIsReportedWithFilinqsReason`, `::testARefusalWithoutABackendReportsItAsUnknown`
- [x] 1.2 It reads `detection.backend` and `detection.outcome` from a completed run.
  - unit: `FilinqRedactionClientTest::testTheBackendThatLookedIsReadFromFilinqsResult`, `::testFilinqsNothingFoundIsNotCalledRedacted`
- [x] 1.3 `WOORedactionService` sends a refused document to manual redaction with reason `filinq_has_no_live_detector` (D2).
  - unit: `WOORedactionServiceTest::testADocumentFilinqRefusedForWantOfADetectorFallsToManualWithItsOwnReason`
- [ ] 2.1 Live check once filinq's change lands: with entity detection switched off, a deels openbaar document on a Woo case lands on the manual list with reason `filinq_has_no_live_detector` and no anonymised file is written.
