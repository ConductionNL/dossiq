# Term notices reach the citizen

Task 5.0 of `opt-out-before-send` (dossiq#3281), approved by Ruben on 2026-10-07.

## Why

`TermijnNotificationService::sendTermijnNotification()` renders a notice and hands it to `BerichtenboxRoutingService::routeToBerichtenbox()` (`lib/Service/BerichtenboxRoutingService.php:63-87`). That method resolves a channel, logs and returns a message id derived from a hash. No transport is called. Every caller stores that record as proof of dispatch, so a case shows a reminder as sent that nobody received.

The callers are the ontvangstbevestiging, the request for more information, the reminder while a term waits on the applicant (`PauseChaseService`, run by `PauseChaseJob` and the engine rung), the doorzending and the applicant messages.

## What changes

- **The channel is case mail.** Every caller addresses a notice to an e-mail address, never a BSN, so digital post cannot carry it. `burger-notifications` names e-mail as a channel.
- **integriq is asked first**, through `OptOutGate` via `CaseMailOptOut`, with category `service` (integriq#2543: the `service` purpose covers case updates and reminders). The ontvangstbevestiging is `statutory`, as the opt-out design names it: it reaches a person who opted out and carries no link.
- **Every non-exempt notice carries integriq's unsubscribe line** and the `List-Unsubscribe` headers through OpenRegister's `UnsubscribeHeaders`.
- **One send per notice.** A new table `dossiq_term_notices` holds a unique key per notice. Two triggers for one reminder send it once. The reminder key is the term and the reminder number.
- **A notice that did not leave throws.** Callers already treat a throw as not sent: the reminder is not counted, the acknowledgement records a failed attempt.
- The beschikking route through `BerichtenboxRoutingService` is not touched.

## Impact

- New: `lib/Service/Termijn/TermNoticeSender.php`, `lib/Service/Termijn/TermNoticeLedger.php`, `lib/Exception/NoticeNotSentException.php`, `lib/Migration/Version0Date20261007190000.php`.
- Changed: `lib/Service/TermijnNotificationService.php`, `lib/Service/Pause/PauseChaseService.php`, `lib/Service/Email/CaseMailOptOut.php` (optional plain body).
- App version 0.4.44 for the migration.

## Rollback

`dossiq.outbound_optout_check=false` turns the integriq question off, as for case mail. Reverting the change restores the old behaviour, which sends nothing.
