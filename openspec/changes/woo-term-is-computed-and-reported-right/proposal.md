---
kind: code
depends_on: []
---

# Proposal: woo-term-is-computed-and-reported-right

Woo capability programme, round 1, wave 1. Statutory. Rows 10.9 and 16.2.

| row | text | our rating today |
| --- | --- | --- |
| 10.9 | A term's end date obeys the Algemene termijnenwet without anyone computing it, including through the API (statutory) | partial (build) |
| 16.2 | How many requests arrived, and how many terms were met | partial (production) |

Implements Ruben's decisions D1 (dossiq owns the Woo request and its term) and D13 (10.9 and
16.2 were re-mapped to this dossiq change, ahead of the migration, because the migration hands
every Woo request to this code).

## Summary

The Woo decision term is rolled by the Algemene termijnenwet on the engine calendar for every Woo case, through the API too, and the term report counts arrivals and met terms correctly.

- Rows: 10.9 (statutory: Woo art. 4.4 and Algemene termijnenwet art. 1) and 16.2.
- Wave 1.
- Dependencies: none. Followed by `dossiq/woo-request-takes-over-from-opencatalogi` (https://github.com/ConductionNL/dossiq/issues/3289) and `dossiq/woo-case-screens-and-objections` (https://github.com/ConductionNL/dossiq/issues/3290).
- Decisions: D1 (dossiq owns the Woo request and its term) and D13 (10.9 and 16.2 are re-mapped here).
- Build rules: openspec/woo-build-rules.md
- Rescoped 2026-10-10: the rolled case deadline, the rolled API end dates and the Woo extension (REQ-WTR-001 to 003) were built by `one-term-engine`, whose `woo-case-type` delta replaces this change's. What stays here is the quarterly report (REQ-WTR-004, REQ-WTR-005).

## The law

- **Woo art. 4.4 lid 1**: the decision on a Woo request is taken within four weeks after receipt.
- **Woo art. 4.4 lid 2**: the term may be extended once, by at most two weeks, with reasons.
- **Algemene termijnenwet (Awt) art. 1 lid 1**: a statutory term that ends on a Saturday, a
  Sunday or a generally recognised holiday is extended to the next day that is none of these.
  This applies by law. Nobody has to compute it.

## Why

What dossiq does today, read on `development` at 55bbc761:

- The case `deadline` is a materialised OpenRegister calculation:
  `dateAdd(startDate, @ref.caseType.processingDeadline)`, at `components.schemas.case.configuration.
  x-openregister-calculations.deadline` in `lib/Settings/dossiq_register.json`. OpenRegister's
  calculation engine has no working-day roll. So a Woo case started on a Thursday whose fourth
  week ends on a Saturday, or on King's Day, carries a deadline the law does not use.
  `CaseInheritedDeadlineListener` rolls only an inherited deelzaak term.
- `WOODeadlineService::extendDeadline()` (POST `/api/cases/{id}/woo/extend-deadline`) writes
  `expectedResolution`, `deadlineVerlengd` and `verdagingReden`. The case schema declares none of
  the three. Its one-extension cap reads `deadlineVerlengd` back, so where OpenRegister drops an
  undeclared key, the cap does not hold. It also never touches the term instance, so the term
  engine and the case disagree after an extension.
- `termijn#verleng` (POST `/api/termijn/instances/{id}/verleng`,
  `DeadlineExtensionService::requestExtension()`) does enforce the extension ceiling
  (`countExtensions` against the definition's maximum). But it stores the caller's `newEinddatum`
  as `endDateCurrent` without the Awt roll. So an end date accepted through the API does not obey
  the Awt.
- `DeadlineReportingService::aggregateByType()` (GET `/api/termijn/reports/kwartaal`) groups by
  `$row['caseType'] ?? 'unknown'`. A `deadlineInstance` has no `caseType` property. It has `case`
  and `deadlineDefinition`, so every term lands under `unknown`, and the report cannot say how
  many Woo requests arrived or how many Woo terms were met.

## What changes

- The case `deadline` is the term engine's rolled date: start plus the case type's term, rolled
  by `TermijnTimerService::rollTermEndFor()` on the organisation's calendar. This applies to every
  case type that declares a term, because the Awt does too, unless the term definition switches
  the roll off (`rollToWorkingDay: false`). Where the case has a statutory term instance, the case
  `deadline` follows that instance's `endDateCurrent` whenever it moves (extension, suspension,
  resumption).
- Every end date dossiq accepts through the API is rolled before it is stored. The date before the
  roll is kept beside it (`endDateBeforeRoll`), so a reader can see the roll happened.
- `WOODeadlineService::extendDeadline()` goes through `DeadlineExtensionService::requestExtension()`
  on the case's Woo term instance. The new end date is the current end plus the case type's
  `extensionPeriod` (P14D), rolled. The undeclared keys `expectedResolution`, `deadlineVerlengd`
  and `verdagingReden` are no longer written, and readers that used them read `deadline` and the
  term instance instead. A second extension is refused by the term engine's ceiling.
- The quarterly report groups each term by the case type of the case it belongs to (`case` to
  `caseType`), falls back to the definition's case type, and adds per type the counts of terms
  received in the quarter, met, missed, running and suspended. A term whose case type cannot be
  resolved is counted under an explicit `unresolved` bucket with its ids listed, never silently
  under `unknown`.

## What does not change

- The supervisor override of Awb 4:14 lid 3 stays as it is. It is not a Woo route, and the Woo
  extend route never sets it.
- Opencatalogi's inspection period. That is `opencatalogi/inspection-period-rolls-on-the-calendar`.
- The calendar itself. OpenRegister owns it.

## Dependencies

None planned. When OpenRegister's calendar classes do not resolve, `TermEndRoll` falls back to the
first ordinary weekday and logs it (existing behaviour, `calendarAnswers()`). That stays, and the
case shows the roll as made without the organisation calendar. A term that names a calendar the
engine cannot resolve is refused (REQ-TERM-060, unchanged).

`woo-request-takes-over-from-opencatalogi` (dossiq, wave 2) depends on this change.
`woo-requester-notices-really-go-out` task 3.1 wires the extension notice onto the call site this
change creates.

## Wave and done

Wave 1. Done means merged on `development` with CI green. 10.9 and 16.2 become `yes` (build) then,
and `production` only once a dossiq store release carries them.
