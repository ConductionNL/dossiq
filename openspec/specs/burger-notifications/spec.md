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

### Requirement: A case type may send its acknowledgement by e-mail as well (REQ-ACK-COPY-001)

A case type's `acknowledgement` declaration MAY set `emailCopy: true`. When it does and the acknowledgement went out through the portal inbox or digital post, the same notice SHALL also be mailed to the sender's address and recorded on the case as a message of its own. A copy that cannot be sent SHALL be recorded as not sent and SHALL NOT change the acknowledgement duty. Without the declaration no copy is sent. The shipped Woo request case type declares it.

#### Scenario: A Woo request filed on the portal is acknowledged in the inbox and by e-mail
@e2e exclude needs a mail server and a portal account; covered by RequesterNoticeSenderTest and AcknowledgementDutyTest
- **GIVEN** a resident files a Woo request on the portal with their e-mail address
- **WHEN** the acknowledgement of receipt goes out
- **THEN** it is in their portal inbox
- **AND** it is mailed to their address
- **AND** the case records two outbound messages, one per channel

#### Scenario: A case type without the declaration sends one message
@e2e exclude covered by RequesterNoticeSenderTest
- **GIVEN** a case type that does not declare `emailCopy`
- **WHEN** the acknowledgement goes to the portal inbox
- **THEN** no e-mail is sent

#### Scenario: A copy without an address does not undo the receipt
@e2e exclude covered by RequesterNoticeSenderTest
- **GIVEN** a case type that declares `emailCopy` and a case without an e-mail address
- **WHEN** the acknowledgement goes to the portal inbox
- **THEN** the duty is met and the copy is recorded as not sent


### Requirement: A case records when it arrived and when its clock starts (REQ-TERM-040)

A case SHALL record `receivedAt`, the moment the submission arrived, and
`termStartsAt`, the first working moment on or after it according to the
engine calendar. It SHALL record `receivedOutsideWorkingHours` as true
when the two differ. All three SHALL be written at creation and SHALL NOT
be recomputed on read.

#### Scenario: a Sunday filing starts on Monday
@e2e tests/e2e/intake-says-when-the-term-starts.spec.ts

- **GIVEN** a working calendar whose week runs Monday to Friday
- **WHEN** a request arrives on Sunday evening
- **THEN** `receivedAt` SHALL be that Sunday evening
- **AND** `termStartsAt` SHALL be the first working moment of Monday
- **AND** `receivedOutsideWorkingHours` SHALL be true

#### Scenario: a filing inside the working window starts at once
@e2e tests/e2e/intake-says-when-the-term-starts.spec.ts

- **GIVEN** the same calendar
- **WHEN** a request arrives on Tuesday at ten
- **THEN** `receivedAt` and `termStartsAt` SHALL be the same moment
- **AND** `receivedOutsideWorkingHours` SHALL be false

#### Scenario: the stamp does not move afterwards

- **GIVEN** a case filed on a day the calendar later declares a holiday
- **WHEN** the case is read again
- **THEN** `termStartsAt` SHALL still be the moment written at creation

### Requirement: The intake confirmation says when the clock starts (REQ-TERM-041)

The confirmation shown after a submission, and the ontvangstbevestiging
sent for it, SHALL each name the case reference, the moment the request
was received, the moment the term starts and the deadline. When
`receivedOutsideWorkingHours` is true, both SHALL add one sentence saying
the term starts on the first working day. Both SHALL ship in Dutch and
English.

#### Scenario: the citizen is told on screen
@e2e tests/e2e/intake-says-when-the-term-starts.spec.ts

- **GIVEN** a request filed on Sunday evening
- **WHEN** the confirmation appears
- **THEN** it SHALL name the case reference, the Sunday it was received, the Monday the term starts and the deadline
- **AND** it SHALL carry the sentence about the first working day

#### Scenario: the mail says the same thing
@e2e exclude no HTTP trigger; the ontvangstbevestiging is sent by AcknowledgementDispatchJob, a background job, so there is nothing for a browser to press. Asserted in tests/Unit/Service/IntakeConfirmationTextTest.php, in both languages

- **GIVEN** the same request
- **WHEN** the ontvangstbevestiging is sent
- **THEN** it SHALL name the same four
- **AND** the start it names SHALL be the one stored on the case

#### Scenario: no explanation when none is needed
@e2e exclude the same background job; asserted in tests/Unit/Service/IntakeConfirmationTextTest.php::testNoExplanationWhenNoneIsNeeded

- **GIVEN** a request filed on Tuesday at ten
- **WHEN** the confirmation appears
- **THEN** it SHALL name the reference, the received moment, the start and the deadline
- **AND** it SHALL NOT carry the sentence about the first working day

### Requirement: A requester notice goes out through a real channel or is recorded as not sent (REQ-WRN-001)

Every notice dossiq sends to a requester SHALL be handed to a real transport: the portal inbox
(a `portaalBericht` in dossiq's register), e-mail (`TermNoticeSender::send()`, which asks
integriq's opt-out first, sends each notice once and needs no signed-in user), or digital post
(`BerichtenboxService::sendMessage()` over `BerichtenboxAdapterInterface`). The send SHALL
return a delivery result with `status` either `sent` or `not-sent`. A `sent` result SHALL carry the
transport's own message id: the `portaalBericht` uuid, the mail's message id, or integriq's tracked
message id. A `not-sent` result SHALL carry a reason code and a sentence. No code path SHALL
derive, hash or invent a message id for a notice no transport accepted. A caller of
`TermijnNotificationService::sendTermijnNotification()` receives a `not-sent` result on the
`NoticeNotSentException` it throws (`getDelivery()`), so a caller that does not read a status
cannot record the notice as sent. The beschikking's e-mail SHALL travel under integriq's
`statutory` purpose, like the acknowledgement of receipt: announcing a besluit is a legal duty
(Awb 3:41), so a case-mail opt-out SHALL NOT stop it and it SHALL carry no unsubscribe link.

#### Scenario: A beschikking reaches a requester who opted out of case mail
- **GIVEN** a requester who opted out of case mail and has only an e-mail address
- **WHEN** a handler sends a signed beschikking
- **THEN** integriq SHALL be asked under the `statutory` purpose and the mail SHALL go out
- **AND** the mail SHALL carry no unsubscribe link

@e2e exclude Needs integriq holding an opt-out and a captured outgoing mail, which the e2e instance does not provide; asserted in PHPUnit by TermNoticeDeliveryTest::testABeschikkingIsStatutoryAndReachesAnOptedOutRequester.

#### Scenario: No transport answers, so nothing reads as sent
- **GIVEN** a Woo case whose requester has only a BSN, and an instance without integriq
- **WHEN** dossiq sends the acknowledgement of receipt
- **THEN** the delivery result SHALL have `status` `not-sent` and reason code `integriq-missing`
- **AND** the result SHALL carry no `messageId`

#### Scenario: Digital post is accepted
- **GIVEN** a requester with a BSN and integriq answering `DigitalPostSendRequestedEvent` with a tracked message id `ip-123`
- **WHEN** dossiq sends a stage notice
- **THEN** the delivery result SHALL have `status` `sent`, channel `digital-post` and `messageId` `ip-123`

#### Scenario: A beschikking no transport took stays not sent
- **GIVEN** a signed beschikking whose requester has no portal subject, no BSN and no e-mail address
- **WHEN** a handler sends it
- **THEN** the beschikking SHALL stay `signed`, with no objection term started
- **AND** the case timeline SHALL get an internal "Beschikking niet verzonden" line with reason code `no-channel`, and no public line
- **AND** a beschikking a transport took SHALL store that transport's channel and message id under `dispatch`

#### Scenario: The old router cannot fake a send
- **GIVEN** the codebase after this change
- **WHEN** `BerichtenboxRoutingService` is asked to route a notice
- **THEN** it SHALL NOT return a `messageId` or a `sentOn`, or it SHALL no longer exist

### Requirement: The channel follows the requester, in a declared order (REQ-WRN-002)

The sender SHALL choose the channel in this order and SHALL record which one it used:

1. the portal inbox, when the case carries a `portalSubject` and portaliq is installed;
2. digital post, when the requester's BSN is known (a person whose `initiatorSourceId` is nine
   digits); whether the requester's message box takes it is integriq's answer, which refuses
   when it does not;
3. e-mail, when the case carries a requester e-mail address (`verzoekerEmail`, or an address
   `CaseContactDirectory::collectAddresses()` returns);
4. otherwise `not-sent` with reason code `no-channel` and the sentence that the case has no
   address for the requester.

When the chosen channel refuses, the sender SHALL try the next available channel before it
answers `not-sent`, and the stored result SHALL name every channel tried with its refusal. A notice
that went into the portal inbox SHALL NOT also be sent as digital post by dossiq, because portaliq
forwards inbox messages to the message box through `PortalMessageBoxRecipient` (no double send).

#### Scenario: A portal requester gets the notice in the portal inbox
- **GIVEN** a Woo case started from the portal, with `portalSubject` set, and portaliq installed
- **WHEN** dossiq sends the acknowledgement
- **THEN** a `portaalBericht` addressed to that `portalSubject` SHALL exist with the acknowledgement text
- **AND** the delivery result SHALL be `sent` with channel `portal-inbox` and that `portaalBericht` uuid

#### Scenario: E-mail when digital post refuses
- **GIVEN** a requester with a BSN and an e-mail address, and integriq refusing with `digital-post-source-unset`
- **WHEN** dossiq sends the information request
- **THEN** the notice SHALL go out by e-mail and the result SHALL be `sent` with channel `email`
- **AND** the stored result SHALL list `digital-post` as tried and refused with `digital-post-source-unset`

#### Scenario: No address at all
- **GIVEN** a Woo case with no portal subject, no BSN or OIN and no e-mail address
- **WHEN** dossiq sends any requester notice
- **THEN** the result SHALL be `not-sent` with reason code `no-channel`

### Requirement: The acknowledgement duty is met only when the acknowledgement went out (REQ-WRN-003)

Awb art. 4:3a requires written confirmation of receipt. `acknowledgementDuty.status` SHALL become
`met` only from a `sent` delivery result, or from a handler's explicit `recordMetAnotherWay()`.
A `not-sent` result SHALL count as a failed attempt: the duty SHALL read `pending` while attempts
remain (`AcknowledgementService::MAX_ATTEMPTS`) and `unmet` after the last one, with `lastError`
holding the reason sentence. A public timeline entry saying the acknowledgement was sent
(`Ontvangstbevestiging verzonden`) SHALL be written only on `sent`. On `not-sent` an INTERNAL
timeline entry SHALL say it was not sent and why.

#### Scenario: The duty is not recorded as met when nothing went out
- **GIVEN** a new Woo case whose requester has no reachable channel
- **WHEN** `AcknowledgementDispatchJob` runs all three attempts
- **THEN** `acknowledgementDuty.status` SHALL be `unmet` and `lastError` SHALL name the missing channel
- **AND** no public timeline entry for the case SHALL say the acknowledgement was sent
- **AND** an internal timeline entry SHALL say it was not sent, with the reason

#### Scenario: The duty is met when the acknowledgement went out
- **GIVEN** a new Woo case from the portal and portaliq installed
- **WHEN** `AcknowledgementDispatchJob` runs
- **THEN** `acknowledgementDuty.status` SHALL be `met` with the channel `portal-inbox`
- **AND** the public timeline SHALL carry `Ontvangstbevestiging verzonden` with the same `sentAt` as the delivery result

#### Scenario: A handler records another way
- **GIVEN** a case whose duty reads `unmet`
- **WHEN** the handler records that receipt was confirmed by phone, with that sentence
- **THEN** the duty SHALL be `met` with `metHow` holding that sentence, as today

### Requirement: The term is suspended only by a request for information that went out (REQ-WRN-004)

Under Awb art. 4:15 lid 1 sub a the term is suspended by asking the requester to complete the
request. `InformationRequestService` SHALL suspend the term only on a `sent` delivery result. On
`not-sent` the term SHALL keep running, the failed send SHALL be recorded on the term instance
through the existing `recordFailedSend()` path with the reason, and the handler SHALL be told the
term was not suspended.

#### Scenario: An unsent information request does not stop the clock
- **GIVEN** a running Woo term and a requester with no reachable channel
- **WHEN** the handler asks the requester for more information
- **THEN** the term instance SHALL keep status running and its end date SHALL not move
- **AND** the response to the handler SHALL say the request was not sent and the term was not suspended

#### Scenario: A sent information request stops the clock
- **GIVEN** a running Woo term and a requester reachable by e-mail
- **WHEN** the handler asks the requester for more information
- **THEN** the request SHALL go out by e-mail and the term SHALL be suspended from that day

### Requirement: An extension reaches the requester with its reason (REQ-WRN-005)

Woo art. 4.4 lid 2 lets the term be extended once by two weeks, with notice and reasons to the
requester. When a Woo term is extended, dossiq SHALL send the requester an `extension` notice
carrying the reason and the new end date, through REQ-WRN-001 and REQ-WRN-002. The extension SHALL
stand when the notice is `not-sent`, and the case SHALL show the extension notice as not sent with
the reason, so the handler can send it another way before the original term ends.
Every extension of a statutory term SHALL send the same notice, whichever route extended it
(Awb 4:14 lid 3 asks that the applicant is told; decision 166), and the requester SHALL be told
once per extension.

#### Scenario: The requester is told about the extension
- **GIVEN** a Woo case from the portal with a running term
- **WHEN** the handler extends the term with the reason "Veel documenten van derden, zienswijzen nodig"
- **THEN** a `portaalBericht` to the requester SHALL carry that reason and the new end date
- **AND** the case SHALL store the extension notice with status `sent`

#### Scenario: The extension notice could not go out
- **GIVEN** a Woo case with no reachable channel
- **WHEN** the handler extends the term with a reason
- **THEN** the term SHALL be extended
- **AND** the case SHALL store the extension notice with status `not-sent` and reason `no-channel`
- **AND** the extend response SHALL say the requester was not told

#### Scenario: A statutory extension outside Woo tells the requester too
@e2e exclude no non-Woo extension flow in the nightly fixtures; unit over the term engine, StatutoryExtensionNoticeTest

- **GIVEN** a vergunning case with a running statutory term
- **WHEN** the handler extends the term through `termijn#verleng` with a reason
- **THEN** the requester SHALL get the `extension` notice with that reason and the new end date
- **AND** a planned or internal term extended the same way SHALL tell nobody

#### Scenario: The requester is told once
@e2e exclude a second send is only visible in the transport; unit over the controller, WOOAssessmentControllerExtensionNoticeTest

- **GIVEN** a Woo case from the portal with a running term
- **WHEN** the handler extends the term
- **THEN** exactly one `portaalBericht` SHALL carry the extension
- **AND** the case SHALL store one extension record

### Requirement: Every requester notice is stored on the case with its result (REQ-WRN-006)

Each notice to a requester SHALL append one record to the case's `outboundCommunications` with:
`moment` (acknowledgement, information-request, stage, extension, decision, transfer), `template`,
`channel`, `status` (`sent` or `not-sent`), `messageId` (only on `sent`), `reasonCode` and
`reason` (only on `not-sent`), `channelsTried`, `attemptedAt`, and `sentAt` (only on `sent`). The
record SHALL be written by the same code that called the transport, from the transport's answer.
The case schema SHALL declare every one of these keys.

#### Scenario: Stage notices are each stored with their own result
- **GIVEN** a Woo case moving from "Ontvangen" to "Zoeken documenten" and then to "Beoordelen"
- **WHEN** each status change sends its declared stage notice
- **THEN** `outboundCommunications` SHALL hold one record per notice, each with its own `status` and `messageId`
