## ADDED Requirements

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
cannot record the notice as sent.

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
