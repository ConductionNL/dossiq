---
kind: code
depends_on: []
---

# Proposal: woo-requester-notices-really-go-out

Woo capability programme, round 1, wave 1. Row 7.11. Highest priority in dossiq: a correctness
fault in a legal duty, and the D1 migration hands every Woo request to this code.

| row | text | our rating today |
| --- | --- | --- |
| 7.11 | The requester hears from the organisation at each step | partial (build) |

Implements Ruben's decisions D1 (dossiq owns the Woo request) and D13 (dossiq joins the measured
stack; 7.11 was re-mapped to this change because notices are recorded but no transport is called).

## Why

The law asks for three things here:

- **Awb art. 4:3a**: the administrative body confirms receipt of an application in writing. A
  Woo request is an application (Woo art. 4.1 lid 1, Awb 4:1).
- **Awb art. 4:15 lid 1 sub a**: the decision term is suspended from the day the body asks the
  requester to complete the request. A request that never reached the requester cannot suspend
  anything.
- **Woo art. 4.4 lid 2**: the body may extend the decision term once by two weeks, and tells the
  requester so, with reasons, before the first term ends.

What dossiq does today, read on `development` at 55bbc761:

- `TermijnNotificationService::sendTermijnNotification()` (lib/Service/TermijnNotificationService.php
  around line 180) hands every rendered notice to
  `BerichtenboxRoutingService::routeToBerichtenbox()`.
- `routeToBerichtenbox()` (lib/Service/BerichtenboxRoutingService.php lines 63 to 87) picks a
  channel name, hashes a `messageId` from the reference, logs one info line and returns
  `{notificationChannel, sentOn, sentBy, messageId}`. It calls no transport. Nothing went out.
- `AcknowledgementService::acknowledge()` (lib/Service/AcknowledgementService.php) then writes
  `acknowledgementDuty.status = met`, appends a record to `outboundCommunications` and writes a
  PUBLIC timeline entry `Ontvangstbevestiging verzonden`. So the statutory duty reads as met, and
  the citizen's own portal timeline says the acknowledgement was sent, when nothing was sent.
- `InformationRequestService` (line 164) suspends the term after `sendTermijnNotification()`
  returns, so the term is suspended on a request that never left the building.
- `DoorzendingNotifier`, `PauseChaseService` and `ApplicantMessage` call the same hollow path.
- `WOODeadlineService::extendDeadline()` sends nothing at all, and no code queues the `extension`
  template, so a Woo extension never reaches the requester.

Real transports already exist in dossiq and are not used by this path:

- digital post: `BerichtenboxService::sendMessage()` over `BerichtenboxAdapterInterface`, whose
  default is `IntegriqAdapter` (dispatches integriq's `DigitalPostSendRequestedEvent`, refuses
  with `{status: refused, refused: true, code, error}` rather than simulating, and returns
  `{messageId, status: sent, sentAt}` only with a tracked integriq message);
- e-mail: `CaseEmailService::sendEmail()` over Nextcloud's `IMailer`;
- the portal inbox: a `portaalBericht` object in dossiq's register (`portaal_bericht_schema`,
  register.d/50-zaakportaal.json), which portaliq shows to the signed-in requester and can announce
  by e-mail.

## What changes

- One sender for requester notices, `RequesterNoticeSender`, replaces the hollow call. It picks a
  channel for the requester, calls that channel's real transport, and returns a delivery result:
  `sent` with the transport's own message id, or `not-sent` with a reason code and a sentence.
- `BerichtenboxRoutingService::routeToBerichtenbox()` stops fabricating a message id. It is either
  retired or reduced to channel selection; it never returns a value that reads as a send.
- `TermijnNotificationService::sendTermijnNotification()` returns the delivery result and every
  caller acts on it. A `not-sent` result never records a duty as met, never writes a public
  "verzonden" line, and never suspends a term.
- `AcknowledgementService::acknowledge()` records the duty `met` only on `sent`. On `not-sent` it
  goes through the existing retry path (`AcknowledgementDispatchJob`, `recordFailedAttempt()`,
  three attempts), and after the last attempt the duty reads `unmet` with the reason. The handler
  can still record that receipt was confirmed another way (`recordMetAnotherWay()`), unchanged.
- Every notice to a Woo requester is stored on the case with its delivery result: the moment
  (acknowledgement, information request, stage notice, extension, decision), the channel, the
  status, the transport's message id, the reason when not sent, and when.
- A Woo term extension sends the requester a notice with the reason and the new end date. The
  extension itself is not undone when the notice fails; the case shows the notice as not sent so
  the handler can act before the first term ends.

## What does not change

- The channel transports themselves. integriq's digital post and Nextcloud mail are used as they
  are.
- Which moments a case type sends (`CaseTypeHandling::sends()`), and the opt-out gate.
- `recordMetAnotherWay()`.
- The extension arithmetic. That is `woo-term-is-computed-and-reported-right`.

## Dependencies

None planned. The integriq digital post seam (`DigitalPostSendRequestedEvent`) exists. portaliq's
inbox already reads `portaalBericht` objects. When integriq is absent, digital post is not
available and the sender falls through to the next channel or records `not-sent` with the reason
`integriq-missing`. When portaliq is absent, the inbox channel is not offered.

`woo-request-takes-over-from-opencatalogi` (dossiq, wave 2) depends on this change.

## Wave and done

Wave 1. Done means: merged on `development` with CI green. Row 7.11 becomes `yes` with status
`build`; it becomes `production` only once a dossiq store release carries it.
