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
and the receipt answer do not depend on the calendar: they are the day the
request came in, which is known. They are written either way.

## D-5. A receipt known to the day is compared by day

A `receiptDate` answer such as `2026-10-06` reads as midnight. Comparing
instants would call every such receipt "outside working hours", a claim
nobody computed. For a day-only receipt the flag compares the receipt day
with the day the term starts on.

## D-6. The received moment comes from the channel when it has one

`ChannelIntake` writes the message's `receivedAt`; the DSO intake writes the
submission moment when `submissionDate` carries a time. Both already set
`startDate` from the same value and keep doing so.
