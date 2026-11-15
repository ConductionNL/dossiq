# Design: every-term-on-the-engine-calendar

## D-1. The audit is a file, not a grep

The 37 is a count from five patterns over `lib/`, and batch 12 said
plainly what it is worth: "A file doing date arithmetic is not
necessarily computing a statutory term." Two of the five patterns,
`->add(` and `->sub(`, are not anchored to a date type at all, so money
and arrays land in the count.

So the deliverable is a table with a verdict per file, in
`docs/research/date-arithmetic-audit-2026-09-14.md`: statutory term,
business date that must roll, or neither, with the reason in one clause.
A number without verdicts invites the next reader to run the same grep
and reach the same uncertainty.

## D-2. The three paths, and what each one needs

- **`BezwaarTermijnScheduler::computeTermijn()`**, line 76. Six weeks
  from bekendmaking under Awb 6:7. The term is right; the landing day is
  not checked. It takes the roll, and the reminder a week earlier takes
  it too, because a reminder on Second Christmas Day reaches nobody.
- **`NoticeOfDefaultService`**, line 171. The ingebrekestelling grace
  under Awb 4:17 is a statutory period with a deadline at its end. It
  takes the roll. Its validity rules do not change, which is what
  `termijnbewaking-op-engine-timers` promised to leave alone.
- **`DeadlinePauseService`**, lines 93 and 172. The pause credit and the
  unused remainder both move `endDateCurrent`, which is the date a
  handler is judged on. The arithmetic stays where it is (REQ-TOT-002);
  the result rolls.

## D-3. The structural test, and why an allowlist

`EveryTermOnTheCalendarTest` reads the audit's verdict column. A file
marked statutory that computes a date without reaching
`WorkingDayCalculator` or the engine's calendar fails the build, naming
the file and the line. A file marked neither is skipped, and its verdict
is the reason.

The allowlist is the same shape `NoLocalCalendarTest` uses in
`terms-on-the-engine-calendar`: an entry needs a reason, and a reason
that says "not a term" has to survive a reader looking at the file. An
allowlist without reasons is how thirty-two files got here.

## D-4. What counts as a statutory term

A period the law names, ending on a date somebody can be held to: the
beslistermijn, the bezwaartermijn, the ingebrekestelling grace, the
hersteltermijn, the dwangsom window. Not a report window, not a
throughput trend, not demo seed data, not a retention period counted in
years where a weekend cannot move the answer.

## D-5. The order matters

The roll rule has to exist before anything can call it, so this change
depends on `terms-on-the-engine-calendar` and starts with the audit,
which needs nothing. That way the audit is publishable even if the roll
lands a week later, and it is the audit, not the three fixes, that tells
us whether the row is worth more work.

## D-6. What the fixture pins

One pair per path: the same input with the roll on and off. Awt art. 1
moves a term ending on a Saturday, a Sunday or a recognised holiday, and
a fixture that only tests a Sunday passes a calculator that knows nothing
about Koningsdag.
