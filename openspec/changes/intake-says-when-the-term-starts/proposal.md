---
kind: code
depends_on: []
---

# Proposal: intake-says-when-the-term-starts

Competitor gap register, row Q8.21 "At intake, does the product tell the
requester when the clock starts, from the calendar"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated no, owner dossiq, slug
`intake-says-when-the-term-starts`, size S. Last sweep of the OpenSpec
phase.

## Why

Someone files a request on Sunday evening. The statutory clock does not
start on Sunday evening, and nothing tells them that. They count eight
weeks from the moment they pressed send, and the municipality counts eight
weeks from Monday.

The register's note: "`WorkingDayCalculator` computes the term and the
movable holidays
(`lib/Service/WorkingDayCalculator.php:94,165,219-230`), and nothing at
intake tells the citizen when it starts: `grep -rniE
'outside.?(working|business).?hours|buiten kantoor' lib src` returns 0.
The nearest is the confirmation with the case number."

The best competitor, verbatim from the register's `best` column: "Frappe
Helpdesk 1.30: raised_outside_working_hours stamped on create and an
administered banner naming the deadline, measured on a Sunday filing
(`_round4/compare/proposed-rows-batch9.md`)". Two things in that: the fact
is stamped on the record at create, and the person is told.

dossiq already sends the ontvangstbevestiging with the deadline
(`burger-notifications` REQ-TERM-008, `TermijnNotificationService`). What
it does not have is the start, and it does not have the sentence that
explains why the start is not the moment the form was submitted.

## What changes

- The case records both moments: `receivedAt`, when the submission
  arrived, and `termStartsAt`, the first working moment the term counts
  from, computed on the same calendar the term counts on.
- `receivedOutsideWorkingHours` is true when the two differ, the way
  Frappe stamps it.
- The confirmation on screen, immediately after submitting, names the case
  reference, the moment it was received, the moment the term starts and
  the deadline.
- The ontvangstbevestiging names the same four. When the two moments
  differ it adds one sentence saying so.
- Nothing is computed twice: the same calendar answers the confirmation
  and the term.

## Ownership

dossiq builds the two fields and the two surfaces. The register's `why`:
"what the citizen is told at intake is dossiq's confirmation; the first
working minute comes from the engine calendar". The calendar itself is
openregister's, consumed through the engine.

## Consumes from

- openregister `working-calendar-admin` (to be specified, register row
  8.12): the administered working calendar, so the first working moment
  is the municipality's answer rather than a hard-coded one.
- dossiq `terms-on-the-engine-calendar` (open, register rows 8.11, 8.12,
  Q8.19, Q8.17): the change that moves the term arithmetic onto that
  calendar. Until it lands, `WorkingDayCalculator` answers and the fields
  are populated from it.

## ADRs

- Company ADR-022: the calendar is the platform's; dossiq reads it and
  keeps no second one.
- Company ADR-031: the working window is administered, not coded.
- Company ADR-025: both sentences ship in Dutch and English, from the
  source keys.

## Capabilities

- Modified: `burger-notifications`: the intake confirmation names when the
  clock starts, not only when it ends.

## Impact

`lib/Settings/dossiq_register.json` (`case.receivedAt`,
`case.termStartsAt`, `case.receivedOutsideWorkingHours`),
`lib/Service/TermijnNotificationService.php`,
`lib/Service/EmailTemplateService.php` (the ontvangstbevestiging default),
the intake confirmation surface in `src/manifest.json`, Dutch and English
strings, `tests/Unit/Service/`, one e2e spec.

## Out of scope

- Changing when the term actually starts. The arithmetic is
  `terms-on-the-engine-calendar`; this change reports it.
- A per-channel working window. One municipal working calendar, as
  `working-calendar-admin` administers it.
