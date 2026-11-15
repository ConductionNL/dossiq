# Design: receipt-and-start-dates-are-set-at-creation

## D-1. One listener, one write

`IntakeTermStartListener` already writes the term stamp onto a new case,
once. The receipt and start dates join that write rather than arriving in a
second listener: two writes on the same create event race each other with
stale payloads, and the second would have to merge around the first.

## D-2. The receipt is read in one place

`IntakeTermStart::arrivalOf()` is the one reader of "when did this arrive".
It now also reads the case's `receiptDate` answer, after `receivedAt` and
before `registrationDate`. `DeadlineCaseCreatedListener` binds the term from
the same method, so the term, the stamp and the dates cannot disagree.

## D-3. What an integration supplied is kept

Every field is written only when empty: `startDate` when the case has none,
the `receiptDate` answer when the case does not answer it, the stamp when
`termStartsAt` is absent. An import carrying a real receipt keeps it, and the
term counts from it.

## D-4. The dates do not wait for the calendar

An unreachable calendar still stamps nothing (REQ-TERM-040). The start date
and the receipt answer do not wait for the calendar: they are the day the
request came in, which is known. They are written either way.

Amended by a-request-form-opens-the-case-at-once (decision 181, Q11): when
the calendar answers and the term starts on a later day, the start date is
that later day. The receipt answer stays the day the request came in.

## D-5. A receipt known to the day is compared by day

A `receiptDate` answer such as `2026-10-06` reads as midnight. Comparing
instants would call every such receipt "outside working hours", a claim
nobody computed. For a day-only receipt the flag compares the receipt day
with the day the term starts on.

## D-6. The received moment comes from the channel when it has one

`ChannelIntake` writes the message's `receivedAt`; the DSO intake writes the
submission moment when `submissionDate` carries a time. Both already set
`startDate` from the same value and keep doing so.

## D-7. The fields are set before the case is stored (11 October 2026)

Two defects were found live on :8098 after #3653, and both came from writing
after the create:

- **The UTC day.** OpenRegister's materialised `startDate` calculation
  (`coalesce(startDate, now)` formatted `Y-m-d`) runs on the creating event
  and formats its own `now` in the process zone, UTC. A case made at 01:52
  CEST on 11 October was stored with 2026-10-10, and the created-event
  listener kept it, because it keeps what a case carries (D-3).
- **No `receiptDate` answer.** The created-event write sent the whole case
  back, including materialised readOnly fields that other listeners had
  moved in the meantime. OpenRegister refused it ("Cannot modify readOnly
  properties: assignedGroupPublicName, deadline"), so no case got its answer
  or its stamp, from the UI or the API.

`IntakeTermStartListener` now also listens to `ObjectCreatingEvent`, at
priority 100 (`IntakeListenerRegistrar::RECEIPT_DATES_PRIORITY`), above
OpenRegister's `CalculationOnSaveListener` at the default 0. It sets the
stamp, `startDate` and the `receiptDate` answer on the entity before the
store, so the calculation finds the day and keeps it, and the `deadline`
calculation counts from the right day. The day is the receipt's calendar
day in the administered zone (`CaseDateNormaliser::formatCalendarDate()`).

The created-event handler stays for the bulk save, which dispatches only
that event, and now writes only the fields it adds through the partial
write (`SearchesObjects::patchObjectAsArray()`). This supersedes D-1's "one
write": on the normal path there is no second write at all.

The OpenRegister calculation keeps its `now` fallback. Making `now` zone
aware is OpenRegister's change to make; with dossiq setting the day first,
the fallback no longer decides a case's start date.
