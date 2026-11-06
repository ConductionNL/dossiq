## ADDED Requirements

### Requirement: dossiq sets the receipt and start dates when a case is created (REQ-TERM-042)

When a case is created, dossiq SHALL set its receipt and start dates; the
create form SHALL NOT ask for them. The receipt SHALL be the first of: a
`receivedAt` the creating path supplied, the case's own `receiptDate` answer,
`registrationDate`, `requestedDate`, the creation moment. dossiq SHALL write
`startDate` as the receipt's calendar day in the administered time zone
(Europe/Amsterdam unless the tenant or the engine calendar names another)
when the case carries no `startDate`. When the term starts on a later day
than the request came in, `startDate` SHALL be the day the term starts
instead (decision 181, a-request-form-opens-the-case-at-once). dossiq SHALL answer the case type's `receiptDate` question with
that day when the type declares one and the case does not answer it. A value
already on the case SHALL be kept. The statutory term SHALL start from that
same receipt (REQ-OTE-02). The dates SHALL be written even when the working
calendar does not answer. dossiq SHALL set these fields before the case is
stored, so the stored case carries them from its first version and no
materialised calculation can fill `startDate` first.

#### Scenario: a case filed by hand gets both dates from the moment it was made
@e2e exclude the dates are written by a create listener with no gesture of its own; covered through the real listener in tests/Unit/Listener/IntakeTermStartListenerTest.php::testACaseFiledByHandGetsItsDatesFromCreation

- **GIVEN** a Woo case type that declares a `receiptDate` question
- **WHEN** a handler files a case on Tuesday 2026-10-06 at 10:00 without a start date or receipt date
- **THEN** the case's `startDate` SHALL be 2026-10-06
- **AND** its `receiptDate` answer SHALL be 2026-10-06
- **AND** its `receivedAt` SHALL be Tuesday 2026-10-06 at 10:00

#### Scenario: a case made just after midnight starts that day in the administered zone
@e2e exclude the day is set by a create listener with no gesture of its own, and a browser cannot choose the clock; covered through the real listener and the real CaseDateNormaliser in tests/Unit/Listener/IntakeTermStartListenerTest.php::testACaseMadeJustAfterMidnightInAmsterdamStartsThatDay and the wiring in tests/Unit/AppInfo/ReceiptDatesRegistrationTest.php

- **GIVEN** the administered zone is Europe/Amsterdam
- **WHEN** a handler files a case on Sunday 2026-10-11 at 01:52 CEST (Saturday 23:52 UTC)
- **THEN** the case's `startDate` SHALL be 2026-10-11

#### Scenario: a request sent on Sunday evening starts on Monday
@e2e exclude the day is set by a create listener with no gesture of its own, and a browser cannot choose the clock; covered through the real listener in tests/Unit/Listener/IntakeTermStartListenerTest.php::testASundaySubmitCarriesItsTermAndStartsOnTheDayTheTermStarts and tests/Unit/Listener/IntakeTermStartListenerTest.php::testTheReceiptAnswerStaysTheDayTheRequestCameIn

- **GIVEN** a working calendar that opens on Monday 2026-10-12 at 08:30
- **WHEN** a request arrives on Sunday 2026-10-11 at 21:40 without a start date
- **THEN** the case's `termStartsAt` SHALL be Monday 2026-10-12 at 08:30
- **AND** its `startDate` SHALL be 2026-10-12
- **AND** its `receiptDate` answer SHALL be 2026-10-11

#### Scenario: a Woo case is stored with its receipt answer
@e2e exclude the answer is set by a create listener with no gesture of its own; covered through the real listener in tests/Unit/Listener/IntakeTermStartListenerTest.php::testAWooCaseIsStoredWithItsReceiptAnswer and tests/Unit/Listener/IntakeTermStartListenerTest.php::testTheCreatedFallbackSendsOnlyTheFieldsItAdds

- **GIVEN** a Woo case type that declares a `receiptDate` question
- **WHEN** a case is created from the UI or the API without a receipt date
- **THEN** the stored case SHALL answer `receiptDate` with the receipt day
- **AND** no later write SHALL be needed to add it

#### Scenario: an imported receipt date is kept and starts the term
@e2e exclude an integration write path with no screen; covered in tests/Unit/Listener/IntakeTermStartListenerTest.php::testAnImportedReceiptIsKeptAndStartsTheTerm and tests/Unit/Listener/DeadlineCaseCreatedListenerTest.php::testAnImportedReceiptDateStartsTheTerm

- **GIVEN** an integration imports a Woo case answering `receiptDate` with 2026-09-01, created on 2026-10-06
- **WHEN** the case is created
- **THEN** its `receiptDate` answer SHALL stay 2026-09-01
- **AND** its `startDate` SHALL be 2026-09-01
- **AND** the statutory term SHALL start on 2026-09-01

#### Scenario: a supplied start date is kept
@e2e exclude covered in tests/Unit/Listener/IntakeTermStartListenerTest.php::testASuppliedStartDateIsKept

- **GIVEN** a channel creates a case carrying `startDate` 2026-10-04
- **WHEN** the case is created
- **THEN** its `startDate` SHALL stay 2026-10-04

#### Scenario: a receipt known only to the day is not called outside working hours
@e2e exclude covered in tests/Unit/Service/IntakeTermStartTest.php::testADayOnlyReceiptOnAWorkingDayIsInside

- **GIVEN** a receipt answered as Tuesday 2026-10-06, without a time
- **WHEN** the working calendar starts the term that same Tuesday
- **THEN** `receivedOutsideWorkingHours` SHALL be false

#### Scenario: a replayed create event writes nothing
@e2e exclude covered in tests/Unit/Listener/IntakeTermStartListenerTest.php::testAReplayWritesNothing

- **GIVEN** a case that already carries its stamp, its start date and its receipt answer
- **WHEN** its create event is handled again
- **THEN** nothing SHALL be written

### Requirement: an intake channel passes on when the request arrived (REQ-TERM-043)

A path that creates a case from a submission whose arrival moment it holds
SHALL write that moment as `receivedAt`: a routed channel message from the
message's `receivedAt`, a DSO permit application from its `submissionDate`
when that carries a time.

#### Scenario: a DSO submission on Sunday evening
@e2e exclude a background intake with no screen; covered in tests/Unit/Service/DsoCaseWritesMatchTheRealSchemaTest.php::testTheSubmissionMomentIsTheReceipt; the channel half in tests/Unit/Listener/IntakeMessageRoutedListenerTest.php::testARoutedMessageOpensACaseAndAnswersTheSlot

- **GIVEN** a DSO permit application submitted on Sunday 2026-10-04 at 20:00
- **WHEN** dossiq creates its case on Monday
- **THEN** the case SHALL carry `receivedAt` Sunday 2026-10-04 at 20:00
