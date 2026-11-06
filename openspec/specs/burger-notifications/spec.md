---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# burger-notifications Specification

## Purpose
Sends proactive, Dutch-language notifications to the burger at key lifecycle moments of a case: receipt confirmation with the statutory deadline, ingebrekestelling receipt explaining the dwangsom tariff, and payment confirmation. Each notification is rendered from a template and delivered via the dossiq notification-router (Nextcloud, email, and portal), queued asynchronously so an SMTP failure never blocks the underlying lifecycle operation.

## Requirements

### Requirement: Burger-notificatie van termijn-events (REQ-TERM-008)

The system SHALL send proactive, Dutch-language notifications to the burger at key lifecycle moments: receipt, extension, ingebrekestelling-receipt, and payment confirmation.

#### Scenario: Receipt confirmation with deadline toezegging

- **GIVEN** an aanvraag is registered and a `TermijnInstance` is created
- **WHEN** the ontvangstbevestiging is sent
- **THEN** the burger SHALL receive a Dutch message containing the case reference, wettelijke termijn, berekende einddatum, a portal status link, and contact info

#### Scenario: Ingebrekestelling receipt explains the dwangsom tariff

- **GIVEN** a valid ingebrekestelling is registered (`geldigheidStatus = geldig`)
- **WHEN** the ingebrekestelling-receipt notification is sent
- **THEN** the burger SHALL receive confirmation of the receipt date, the grace-period end date, dwangsom-tariff transparency (€23/day rising, max €1.442), and a statement that the dwangsom stops on beschikking

#### Scenario: Payment confirmation notification

- **GIVEN** a dwangsom is paid and `DwangsomUitbetaling.status = betaald`
- **WHEN** the payment-confirmation notification is sent
- **THEN** the burger SHALL receive a message with the bedrag, payment date, payment reference, and a confirmation that it was paid as automatic compensation for niet-tijdig-beslissen

#### Scenario: Notifications fire at the correct lifecycle moments without blocking

- **GIVEN** the lifecycle triggers emitted by termijn creation, extension, ingebrekestelling, and payment
- **WHEN** each trigger is consumed
- **THEN** the matching template SHALL be rendered and delivered via the dossiq notification-router (Nextcloud + email + portal)
- **AND** delivery SHALL be queued asynchronously so an SMTP failure does not block the lifecycle operation

### Requirement: The acknowledgement of receipt has a trigger (REQ-TERM-020)

A case created from an electronic submission SHALL trigger an
ontvangstbevestiging without a person acting, per Awb 4:3a. The trigger
SHALL be a declaration on the case type and SHALL NOT be a step in a
workflow definition. A case type SHALL declare which intake channels owe
an acknowledgement, defaulting to every electronic channel. A case created
by hand SHALL NOT owe one unless the case type says so.

#### Scenario: an aanvraag arrives through the portal
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case type with the default declaration
- **WHEN** a citizen submits an aanvraag through the portal
- **THEN** an ontvangstbevestiging SHALL be sent to them
- **AND** no handler action SHALL be needed

#### Scenario: an aanvraag arrives by mail
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case created from an accepted inbound message
- **WHEN** the case is created
- **THEN** an ontvangstbevestiging SHALL be sent to the sender

#### Scenario: a case typed at the balie owes nothing
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case type whose declaration names electronic channels only
- **WHEN** a handler creates a case by hand
- **THEN** no ontvangstbevestiging SHALL be sent

#### Scenario: a case type cannot silently lose the duty

- **GIVEN** a case type whose acknowledgement declaration is removed
- **WHEN** it is published
- **THEN** publication SHALL warn that the statutory acknowledgement is off
- **AND** the warning SHALL name Awb 4:3a

### Requirement: The acknowledgement says what was received and by when it is decided (REQ-TERM-021)

The message SHALL name the case kenmerk, what was received, the statutory
term and its end date, how to follow the case, and who to contact. It
SHALL be sent in Dutch, and in the language the case type declares where
it declares one. It SHALL quote back only what the case type allows the
citizen to see.

#### Scenario: the citizen learns their kenmerk and their deadline
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case with a bound statutory term
- **WHEN** the ontvangstbevestiging is sent
- **THEN** it SHALL carry the kenmerk and the end date of the term

#### Scenario: a field the citizen may not see is not quoted back

- **GIVEN** a case carrying a field marked not visible to the citizen
- **WHEN** the ontvangstbevestiging is rendered
- **THEN** that field SHALL NOT appear in it

#### Scenario: a case with no statutory term still confirms receipt
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case type with no beslistermijn
- **WHEN** a case is created from an electronic submission
- **THEN** an ontvangstbevestiging SHALL still be sent
- **AND** it SHALL say that no statutory term applies

### Requirement: The channel is the citizen's where they chose one (REQ-TERM-022)

The acknowledgement SHALL be sent through the channel the citizen chose,
where a choice is recorded, and otherwise through the case type's default.
Where the case type declares that content stays on the platform, the
message SHALL say a message is waiting and SHALL carry no case content.

#### Scenario: a citizen who chose the portal is not mailed the content
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a citizen whose recorded choice is the portal
- **WHEN** the ontvangstbevestiging is sent
- **THEN** it SHALL be delivered in the portal

#### Scenario: content stays on the platform
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case type declaring that content stays on the platform
- **WHEN** the acknowledgement is sent by e-mail
- **THEN** the e-mail SHALL say a message is waiting
- **AND** it SHALL carry no case content

#### Scenario: no recorded choice falls back to the case type's default

- **GIVEN** a citizen with no recorded channel choice
- **WHEN** the acknowledgement is sent
- **THEN** the case type's default channel SHALL be used

### Requirement: The acknowledgement is recorded on the case (REQ-TERM-023)

Sending an acknowledgement SHALL write an outbound communication record on
the case carrying the moment, the channel, the recipient and the template
version. Whether receipt was confirmed SHALL be answerable from the case
without reading a server log.

#### Scenario: a handler can see that receipt was confirmed
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case whose acknowledgement was sent
- **WHEN** a handler opens the case
- **THEN** the record SHALL show the moment, the channel and the recipient

### Requirement: A failed acknowledgement is visible and retried (REQ-TERM-024)

A failure to send SHALL NOT block the creation of the case, SHALL be
retried, and SHALL show on the case as an unmet statutory duty until it is
sent or a person records that it was met another way. It SHALL NOT be
recorded only in the application log.

#### Scenario: a mail server outage does not stop a case being created
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** an unreachable mail transport
- **WHEN** a citizen submits an aanvraag
- **THEN** the case SHALL be created
- **AND** the acknowledgement SHALL be queued

#### Scenario: an acknowledgement that never went out is findable
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case whose acknowledgement failed every retry
- **WHEN** a handler opens the case
- **THEN** the case SHALL show the duty as unmet

#### Scenario: a handler records that it was met another way
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case showing an unmet acknowledgement
- **WHEN** a handler records that it was confirmed by post
- **THEN** the duty SHALL read met
- **AND** the record SHALL name who said so and when

### Requirement: Other automatic moments ride the same declaration (REQ-TERM-025)

A case type SHALL declare a list of moments at which the applicant is told
something automatically. The acknowledgement SHALL be the entry carrying
the statutory flag. A request for something from the applicant SHALL be a
distinct moment from a status change.

#### Scenario: asking for something is not the same message as moving on
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case type declaring a moment for an incomplete aanvraag and a moment for a status change
- **WHEN** the case becomes incomplete
- **THEN** the applicant SHALL receive the request message
- **AND** it SHALL NOT be the status-change message

#### Scenario: the statutory moment cannot be deleted unnoticed

- **GIVEN** a case type's declared moments
- **WHEN** the statutory entry is removed and the type is published
- **THEN** publication SHALL warn, naming Awb 4:3a

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

### Requirement: Every date in a letter reads the same way (REQ-LPD-001)

Every date dossiq prints in a letter to an applicant (receipt, extension, notice of default, request for missing information and its reminder) SHALL be written as the day, the month in full and the year ("10 augustus 2026"), in the letter's language, whether the store holds a date or a moment. A letter without a date SHALL keep its placeholder.

#### Scenario: A resident reads her receipt
- GIVEN Sanne's request was received on 15 June 2026 and must be decided by 10 August 2026
- WHEN she opens the Ontvangstbevestiging in her messages
- THEN it reads "ontvangen op 15 juni 2026 en de beslistermijn start op 15 juni 2026" and "uiterlijk op 10 augustus 2026"
- @e2e exclude pinned by `AcknowledgementDutyTest::testTheAcknowledgementNamesTheStartAndTheDueDate` and `TermLettersTest::testEveryDateInALetterReadsTheSameWay`

### Requirement: A requester letter names the case by its number

A letter to a requester (the receipt, an extension notice, a transfer notice) SHALL name the case by the case number its caller passes as the kenmerk, in the title and in the body. It SHALL fall back to the term instance's case link only when the caller passes no kenmerk. It SHALL NOT show a case UUID when the case carries a number.

#### Scenario: The receipt names the case number when the term links the case by UUID
- **GIVEN** a case with identifier `2026-0117` and a bound term whose `case` is the case UUID
- **WHEN** the acknowledgement of receipt is sent
- **THEN** the title SHALL read "Ontvangstbevestiging zaak 2026-0117"
- **AND** the body SHALL name kenmerk `2026-0117` and SHALL NOT contain the UUID

@e2e exclude Rendering is covered by the unit test through AcknowledgementService::acknowledge(); the portal inbox only displays the stored subject.

### Requirement: Every requester letter names the case by its number

Every letter to a requester rendered from a term template (the receipt, the information request, the reminder on a paused term, the inadmissibility letter, the transfer notice, the default notice and the penalty payment) SHALL name the case by its case number in the title and in the body. When the reference a caller hands over is a case UUID, the number SHALL be read from that case. A reference that is already a number SHALL be used as written. The opt-out link and the delivery record SHALL keep the case UUID.

#### Scenario: The information request names the case number when handed the UUID
- **GIVEN** a case with identifier `2026-0018` and a caller that passes the case UUID as `case`
- **WHEN** the information request is sent
- **THEN** the title SHALL read "Wij hebben nog iets nodig voor zaak 2026-0018"
- **AND** the body SHALL NOT contain the UUID

#### Scenario: A case that cannot be read keeps the reference it was given
- **GIVEN** a caller that passes a case UUID and a case that cannot be read
- **WHEN** a letter is rendered
- **THEN** the letter SHALL name the reference it was given rather than fail

@e2e exclude Rendering is covered by unit tests through TermijnNotificationService::sendTermijnNotification(); the portal inbox only displays the stored subject.
