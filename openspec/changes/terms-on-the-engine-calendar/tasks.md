# Tasks: terms-on-the-engine-calendar

Tier: V1. Kind: code. Rows 8.11, 8.12, Q8.19 (dossiq halves), Q8.17
(history only). Statutory.

- [x] 1.1 Legal confirmation: **not obtained, and that is why the default is
  off.** Nobody qualified to read Awt art. 3 has confirmed the
  recognised-holiday list against dossiq's case types, and this change does
  not pretend otherwise. `rollToWorkingDay` therefore ships defaulting to
  false, which means shipping it moves no date anybody is already counting
  on. The flip to true for `legalBasis` Algemene termijnenwet waits on a
  line here naming who confirmed the list and when. A technical change
  cannot supply that line, so recording its absence is the deliverable.
- [x] 1.2 `register.d/60-termijnbewaking.json`
  `deadlineDefinition.rollToWorkingDay`, default false, with the legal
  reason for the default in its own description.
  - `@spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md`
- [x] 1.3 The roll: `lib/Service/Termijn/WorkingDayRoll.php` asks the
  organisation calendar through `SlaCalculator::add(0 business days)`, which
  returns the same instant on a working day and the start of the next
  working one otherwise. `TermijnService::createTermijnInstance()` applies
  it to `endDateCalculated`.

  **The armed timer inherits it rather than rolling a second time.**
  `TermijnTimerService::slaDaysFor()` derives the SLA from
  `endDateCurrent`, so one computation decides both the stored date and the
  deadline the engine counts to. A roll applied again at arming time is how
  the two would come to disagree, and the spec's "the armed timer SHALL
  carry the roll rule" is satisfied by the date it is armed with.

  Fixture trio in `WorkingDayRollTest`: Koningsdag, an engine answer earlier
  than the date asked about (refused, because the Awt never shortens a
  term), and the flag off.
- [x] 1.4 The zone. Already satisfied, with evidence rather than new code:
  `CaseDateNormaliser::timeZone()` asks the engine working calendar first
  (`engineCalendarZone()`), and the calendar had no zone to answer until
  ConductionNL/openregister#3869 gave it one and set the seeded
  `nl-national` to `Europe/Amsterdam`. `TermZoneIsTheCalendarsTest` pins the
  order.

  **One deviation from REQ-TERM-016, recorded rather than silent.** The
  requirement says the tenant zone is not read. It is not, when a calendar
  answers. With no calendar at all the tenant zone is still the fallback,
  because the alternative is the process default, which is invisible from
  the data and differs between servers.
- [x] 2.1 `tests/Unit/Architecture/NoLocalCalendarTest.php` with
  `no-local-calendar.allowlist.json`. One entry: `WorkingDayCalculator`,
  retiring with termijnbewaking phase 3.2. The test looks for the LIST, not
  the words: `Kcc\SlaCalculator` names Koningsdag in a docblock and holds
  nothing, and a scanner matching names would open a defect against a file
  that already delegates.
- [x] 2.2 Termijn settings: the zone is stated, and a definition that asks
  for the roll carries a pill. When no calendar answers the page says so,
  because a roll that could not be made and a roll that was not needed
  produce the same plausible date.
- [x] 3.1 `case-terms`: every term carries the moves its deadline has made,
  read from the engine's superseded-timer events and never journalled here.
  Today those are the extensions and the pauses; a move caused by a calendar
  change arrives once openregister's `calendar-change-recomputes-timers`
  lands, and the panel needs no change for it.
- [x] 4.1 `openspec validate terms-on-the-engine-calendar --strict`.
