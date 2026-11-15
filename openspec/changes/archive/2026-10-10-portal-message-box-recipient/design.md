# Design: portal-message-box-recipient

Read at dossiq development `12b622c7d` and portaliq development `4176916`.

## Context

- `portaalBericht` (`register.d/50-zaakportaal.json`): `caseId`,
  `direction` (`citizen_to_handler`, `handler_to_citizen`), `recipientRef`.
- `case`: `initiatorType` (`person`, `company`, `contact`),
  `initiatorSourceId` (the BSN for a person), `portalSubject`.
- portaliq `MessageBoxSender::recipient()` calls the method with the message
  id from a background job and sends nothing unless it gets a non-empty
  string. It writes the value nowhere.

## D1. Who is named

The applicant's BSN, only when the message is the organisation's
(`handler_to_citizen`), names a case whose applicant is a person with a BSN
that passes the 11-proef, and is addressed to that applicant (`recipientRef`
equals the case's `portalSubject`). Otherwise null.

## D2. No double send

Dossiq's own message box letters (compose dialog through
`BerichtenboxService`, decisions and term notices through
`BerichtenboxRoutingService`) write no `portaalBericht`, so no inbox message is
one dossiq already delivered. A path that ever writes both must answer null
for its messages.

## D3. The read runs as the system

The call comes from a background job with no user; the read goes through
`ObjectService::runAsSystem()` with the message id as the only input. The BSN
is never logged.
