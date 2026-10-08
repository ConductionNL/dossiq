---
kind: config
depends_on: [email-case-matching, inbound-mail-filters]
---

# Proposal: inbound-messages-consume-integriq

Competitor gap scan of 2026-09-18, pack b, rows 6.5 "Organisation-wide
message inbox with assign to case" and 6.10 "Import .msg or .eml into a
case". Both scored no. Owner dossiq. Size M.

## Why

Two halves of one hole, and integriq closed the other side of both today.

**A message cannot be filed on a case a person picks.** The intake log is
real: `src/manifest.d/37-mail-intake.json` puts MailIntakeLog in the menu,
`src/views/intake/MailIntakeLogView.vue` lists every message the mailbox
processed with the filter verdict that decided it, and
`lib/BackgroundJob/InboundEmailJob.php` reads the Nextcloud Mail account
rather than opening IMAP itself. Four acts are routed: release, junk,
bounce and move. `MailIntakeController::release()` files the message on
`$entry['case']`, the case the matcher already chose, and `move()` moves it
to a mail folder. So a handler who knows the message belongs on case
2026-114 can do nothing with that knowledge. An unmatched message becomes a
new case only when an administrator named a fallback case type, and
`lib/Service/Email/UnmatchedMailIntake.php` leaves that empty by default.

**A saved mail file is an opaque attachment.** integriq#2052 ships
`POST /api/mail-intake/import`, which takes a saved `.eml` or `.msg` and
produces the same `message` object the poller produces. The `.msg` reader
is a real MS-CFB reader, and the bytes decide which reader runs, not the
file name. dossiq calls it from nowhere. Drop a `.msg` on a case today and
you get a file nobody can read without Outlook.

**dossiq does not answer when integriq asks.** integriq offers every new
message to the owning app through `MessageReceivedEvent`, with a result
slot the listener answers `linked`, `created` or `declined`.
`lib/AppInfo/Registrar/CrossAppListenerRegistrar.php` binds one integriq
event, `DeliveryConcludedEvent`, and nothing listens for messages. Silence
lands in integriq's `unassigned`, which is correct behaviour on integriq's
part and means dossiq is the app that went quiet.

## What changes

- A handler picks a case for a message in the intake log, and the message
  is filed there with the reason recorded. Picking is an act on the log,
  not a second matcher: the automatic match keeps deciding first.
- dossiq listens for `MessageReceivedEvent` and answers it. A message
  whose reference names a case is `linked`, a message that meets the
  fallback rule is `created`, and everything else is `declined` in words
  integriq can record.
- A `.eml` or `.msg` dropped on a case is handed to integriq's import
  endpoint and filed as a message on that case, keeping the original file
  beside the parsed one.
- dossiq keeps no second parser. Reading MS-CFB in two apps is two answers
  to one question, and the one in integriq is tested.

## Ownership

integriq owns the mailbox, the protocols, the parsers and the event
(`mail-intake-creates-cases`, merged as #2052). dossiq owns the case, the
filing decision and the log surface. Nextcloud Mail keeps the credential
and the OAuth flow, which is decision D12 and stays.

## ADRs

- Company ADR-022: dossiq consumes the intake seam and wraps no transport.
- Company ADR-102: absent configuration fails closed with a reason.

## Capabilities

- Modified: `case-email-integration`: a message reaches the case a person
  chooses, and a saved mail file is read rather than stored whole.

## Impact

`lib/AppInfo/Registrar/CrossAppListenerRegistrar.php`;
`lib/Listener/MessageReceivedListener.php` (new);
`lib/Controller/MailIntakeController.php` (the file-on-case act);
`lib/Service/Email/InboundMailIntake.php`;
`src/views/intake/MailIntakeLogView.vue`; `src/manifest.json` (the case
drop path for a mail file); `appinfo/routes.php`.
