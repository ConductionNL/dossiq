---
kind: code
depends_on: [intake-triage-and-refusal]
---

# Proposal: an-intake-message-opens-a-case

Found while scanning parity ledger rows 1.5 and 1.9. integriq receives on
four channels and nobody answers it: dossiq registers no listener for
`IntakeMessageRoutedEvent`, so every message integriq routes at a case is
held with the reason "No app opened a case for this message".

## Why

integriq's `intake-channels-beyond-mail` shipped the whole receiving half. A
channel adapter normalises the message, a routing rule names the case type it
opens, and `IntakeRoutingService::route()` dispatches
`IntakeMessageRoutedEvent` and then reads the result slot back. When the slot
is empty it holds the message in the review inbox and says why.

Measured on 2026-09-18 against dossiq `parity/round2` at `c3bdf65d`:
`grep -rn IntakeMessageRoutedEvent lib/` returns nothing. dossiq listens to
exactly one integriq event, `DeliveryConcludedEvent`. So the slot is always
empty, and every form submission, messaging message and public space report
integriq receives ends in the review inbox, whatever the rule said.

Three adapters ship today (`FormSubmissionAdapter`,
`MessagingChannelAdapter`, `PublicSpaceReportAdapter`) and a fourth is
proposed (`teams-messages-open-cases`). All four are dark for the same
reason, and one listener lights all four.

This is not the mail path. Ruben's decision D12 put the mail account on
Nextcloud Mail, not integriq, and dossiq's own IMAP intake stays exactly as
it is. This change is about the channels that are not mail.

## What changes

- A listener on `OCA\Integriq\Event\IntakeMessageRoutedEvent`, registered
  the way `DeliveryConcludedEvent` already is, in
  `lib/AppInfo/Registrar/CrossAppListenerRegistrar.php` and guarded on the
  class existing, so an instance without integriq is unaffected.
- The listener opens a case through dossiq's existing intake conventions:
  the case type the rule named, the initial status that type declares, the
  term started the way `intake-says-when-the-term-starts` decided, and the
  channel recorded as the intake channel. It SHALL NOT write a case object
  directly, for the reason `FormsIntakeService` does not: a raw insert skips
  the clock and the case looks correct until somebody counts the days.
- The correspondent on the message is attached as the requester when it
  resolves to a known person or organisation, and left on the case as a name
  and an address when it does not. A message from somebody we do not know is
  still a case.
- The files the event carries are attached to the new case.
- The listener answers the slot with the case it opened, so integriq marks
  the message routed instead of holding it.
- A message dossiq refuses SHALL leave the slot empty and say why in the
  log, so integriq's review inbox carries the reason rather than a silence.
  Triage is dossiq's `intake-triage-and-refusal`, and a refusal stays a
  refusal.

## What this change does not do

- It opens no channel and it reads no mailbox. Receiving is integriq's.
- It does not touch `InboundMailIntake`, `UnmatchedMailIntake` or
  `CaseEmailMatchJob`. Mail arrives the way D12 says it does.
- It writes no routing rules. Which channel opens which case type is
  configuration in integriq, and dossiq offering a second place to say it
  would be two answers to one question.

## Capabilities

### New Capabilities

- `intake-from-a-channel`: how a message integriq received becomes a case,
  and what happens when it cannot.

## Impact

- **PHP**: `lib/Listener/IntakeMessageRoutedListener.php`, the registration
  in `lib/AppInfo/Registrar/CrossAppListenerRegistrar.php`, and a service
  that reuses the intake conventions rather than restating them.
- **Schemas**: none.
- **Frontend**: none. A case opened this way is a case.
- **Backwards compatible**: an instance without integriq registers nothing,
  and an instance with integriq and no routing rules sees no change.
