# Design: one-date-write-path

## D-1. The unit of measurement is the writer, not the field

Ten batches of the competitor register measured a term engine by finding
where a date is stored and reading the arithmetic once. Gitea proves that
is the wrong unit. It has a correct end of day rule, a correct
administered zone and a correct implementation of both, on one of three
paths, and a caseworker still sees the wrong day.

So the instrument here is an enumeration of writers, not a reading of
arithmetic. Nine write paths, listed in the proposal with their line
numbers. The test that follows enumerates them mechanically, so the list
cannot go stale.

## D-2. One normaliser, and it is public

Nine private normalisers is not nine implementations of one rule. It is
nine rules, because a private method cannot be reused and each author
wrote what their caller needed. `CaseEnricher::normaliseDate()` trims the
time off. `WOODeadlineService::parseIsoDate()` forces midnight.
`DwangsomPaymentCallbackController::parseDate()` keeps the time. Those
three disagree about what a date is, and all three run against the same
`case.endDate`.

`CaseDateNormaliser` is a service, injected, with three methods. A
calendar date returns `Y-m-d`. A moment returns ATOM with a real offset.
A parse either returns a `DateTimeImmutable` or throws, because a null
return is what let every caller invent its own fallback.

## D-3. Refuse, do not return null

Seven of the nine private normalisers return null on an unreadable value,
and every caller then decides what null means. `AdviceService` stores the
raw string instead. `ConsultationService` substitutes today. Those two
branches are how a typo becomes a deadline.

A parse that throws forces the decision to the write path, where the
handler is still on the screen and can be told.

## D-4. The zone is read once, from the administrator

`tenantConfiguration.timezone` already exists, already validates against
five IANA identifiers, and is already stored. It is read by nothing. This
change gives it its one reader.

Five StUF files hard-code `Europe/Amsterdam`. For a Dutch municipality
that literal is correct today and correct by accident. For the Belgian
tenants `ALLOWED_TIMEZONES` explicitly admits, it is wrong. They read the
tenant zone like everything else.

## D-5. The engine calendar wins when it lands

openregister `calendar-time-zone` (openregister#3688) makes the zone a
property of the working calendar. When it ships, `CaseDateNormaliser`
reads that and `tenantConfiguration.timezone` becomes the fallback for an
instance with no calendar. Two readers, one answer, and the switch is
inside the normaliser rather than in nine controllers.

That ordering is why this change can start now. It does not wait on
openregister; it creates the single place that consumes openregister
later.

## D-6. The structural test is the deliverable

The nine paths can be moved in an afternoon and drift back in a month.
ADR-011 already says to search before implementing a utility, and the
nine private normalisers are that rule failing nine times without anyone
noticing.

`OneDateWritePathTest` fails on a new `private function` in `lib/` whose
name or body parses or formats a date, and on a bare `date(` or
`new DateTimeImmutable(` outside `CaseDateNormaliser`. The failure
message names the normaliser and the method to call, because a structural
test that only says no teaches nothing.

## D-7. The round trip is the proof

A structural test proves one code path exists. It does not prove the path
is correct. The round trip submits one date, `2028-01-31`, through all
nine write paths against an instance whose tenant zone is
`Europe/Amsterdam`, reads each stored value back, and asserts all nine are
the same string.

It is the test that would have caught Gitea, and the test that would have
caught the `easter_date()` bug this tree already shipped
(`tests/bootstrap.php:63-65`), where a CEST midnight timestamp read one
day early under `date.timezone=UTC`.

## D-8. Stored values are left alone

Existing rows hold whatever the nine paths wrote. Rewriting them means
deciding, per row, which of nine rules produced it, and getting that
wrong changes a statutory deadline on a live case.

The structural test stops new disagreement. A backfill needs the corpus
measured first, and that is its own change.
