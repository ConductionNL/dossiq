# Date arithmetic under lib, audited file by file

Change: `every-term-on-the-engine-calendar`, task 1.1. Row Q8.23 of the
competitor gap register, "is the calendar the deadline engine reads the
same calendar the organisation administers".

This file is the deliverable the proposal asked for: not a count, but a
verdict per file. The structural test
`tests/Unit/Architecture/EveryTermOnTheCalendarTest.php` reads the verdict
column of the table below, so a row here is a fact the build keeps true
rather than a note somebody wrote once.

## How the set was built

Patterns, over `lib/**/*.php`, file names only:

```
grep -rlE "modify\('[+-]|new DateInterval|->add\(|->sub\(|strtotime\('[+-]" lib/ --include=*.php
```

Read at `16f00124a` (development, 2026-09-14). The same five patterns
return the same 37 files at `9c478d810`, the commit the proposal counted
at, so the set has not drifted since the row was rated.

Two of the patterns, `->add(` and `->sub(`, are not anchored to a date
type. They match `IJobList::add()` as readily as `DateTimeImmutable::add()`,
which is why two of the 37 rows below have the verdict `neither` with the
reason "not date arithmetic". The count is an upper bound on the problem,
never the problem itself.

## The verdicts

- `statutory`: a period the law names, ending on a date somebody can be
  held to. The beslistermijn, the bezwaartermijn, the ingebrekestelling
  grace, the hersteltermijn, the dwangsom window, the Woo decision term.
  Algemene termijnenwet art. 1 moves such a date off a Saturday, a Sunday
  or a recognised holiday, so it has to reach the working calendar.
- `business`: a date the organisation is judged on but the law does not
  name. It reads better on a working day and nobody is in breach when it
  does not.
- `neither`: a report window, a throughput bucket, a retention period in
  years, demo seed data, an intraday service norm, or a pattern false
  positive. A weekend cannot change the answer, so the calendar is not on
  its path.

## Counts

| | at `9c478d810` (proposal) | at `16f00124a` (this change, before) | after this change (task 3.1) |
|---|---|---|---|
| files matching the patterns | 37 | 37 | 38 (39 since `case-recycle-window`) |
| of those, reaching a working calendar | 5 | 5 | 19 |
| of those, verdict `statutory` | not measured | 16 | 16 |
| `statutory` files reaching a calendar | not measured | 3 | 15 |
| `statutory` files allowlisted with a named owner | not measured | n/a | 1 |

`case-recycle-window` added a thirty-ninth, `lib/Service/Recycle/RetentionClocks.php`: it
counts a lawful-purpose retention in months, which is the AVG side of the same question
`ArchivalNominationDeriver` answers for the Archiefwet, and it carries the same verdict.

`lifecycle-acts-on-the-case` added a fortieth, `lib/Service/Lifecycle/SilenceCloseService.php`.
It counts the administered period of silence a case type declares (REQ-LIFE-04) and the date
the applicant is told the case will close on. It is deliberately NOT statutory: nothing in the
Awb counts sixty days of nobody answering, the period is a gemeente's own setting, and rolling
it to a working day would move a date nobody is owed. Every other date in that change goes
through `CaseDateNormaliser`, which is why only this one line matches the patterns.

The set grew by one file, and by nothing else: `lib/Service/TermijnTimerService.php`
now calls the engine's `SlaCalculator::add()`, which the `->add(` pattern
matches. It is the bridge, not a term, and its row says so.

The proposal counted five files referencing `WorkingDayCalculator`, and
those five are all inside the 37. A sixth file references it at
`16f00124a`, `lib/Service/Milestone/StalledCaseDetector.php`, which does no
date arithmetic of its own and so is not one of the 37. Two of the five,
`lib/Service/Kcc/SlaCalculator.php` and the calculator itself, have the
verdict `neither`, which is why three rather than five statutory files
reached a calendar before this change.

## The table

Lines are the matching lines at `16f00124a`. `Reaches` names what the file
consults for the day a date lands on, after this change.

| file | lines | verdict | reaches | reason |
|---|---|---|---|---|
| `lib/BackgroundJob/AcknowledgementDispatchJob.php` | 136 | neither | | the retry counter for an acknowledgement that failed to send, `attempt + 1` passed to `IJobList::add()`. It is one of the `->add(` matches the pattern cannot anchor to a date type |
| `lib/BackgroundJob/AdviceDeadlineJob.php` | 89 | neither | | a look-ahead window the daily scan uses to pick which advices to remind on, not a date anyone is held to |
| `lib/BackgroundJob/DsoDeadlineJob.php` | 196, 205 | statutory | `WorkingDayCalculator` | the Omgevingswet decision term; the day walk is already the calculator's |
| `lib/Flow/DossiqAskPersonNode.php` | 648, 669 | neither | | a flow task due date and a node timeout in minutes, both process plumbing |
| `lib/Flow/DossiqRequestDecisionNode.php` | 615 | neither | | a node timeout in minutes |
| `lib/Listener/AcknowledgementOnCreateListener.php` | 113 | neither | | queues the acknowledgement with attempt 1 through `IJobList::add()`, the same unanchored match. No date is computed here |
| `lib/Listener/CaseInheritedDeadlineListener.php` | 181 | statutory | engine calendar | a deelzaak inherits the parent case type's term, so it inherits the term's end date |
| `lib/Service/Actions/ScheduleReminderHandler.php` | 162, 167 | neither | | when a reminder background job runs |
| `lib/Service/Archival/ArchivalNominationDeriver.php` | 259 | neither | | a retention period counted in years, where a weekend cannot move the answer |
| `lib/Service/Beschikking/BezwaarTermijnScheduler.php` | 76, 77, 114 | statutory | engine calendar | Awb 6:7, the six week bezwaartermijn and its reminder; line 114 is the archive trigger the day after and follows the rolled end |
| `lib/Service/Beschikking/OpenRegisterArchivalAdapter.php` | 121, 153 | neither | | a retention period from the selectielijst, in years |
| `lib/Service/Bezwaar/AdvisoryCommitteeService.php` | 178 | business | | the committee's own advice deadline, a house norm the Awb does not name |
| `lib/Service/Bezwaar/HearingSchedulePlanner.php` | 106, 153 | neither | | the Awb 7:4 lid 2 inzage floor is a minimum notice counted backwards from the hearing; rolling an end date forward would shorten it |
| `lib/Service/Bezwaar/HearingService.php` | 570 | neither | | a proposed hearing date two weeks out, which the planner then reschedules |
| `lib/Service/CaseLifecycleService.php` | 516, 521 | statutory | engine calendar | extends a case end date by the case type's period, which is the term a handler is judged on |
| `lib/Service/CaseTermsService.php` | 249 | statutory | engine calendar | binds the planned end, the internal target, the phase term and a case type's fixed closing date; every one of them is a date somebody is held to, and `endAfter()` is the single line that turns a day count into one |
| `lib/Service/ComplaintAnalyticsService.php` | 247 | neither | | a six month reporting window |
| `lib/Service/ComplaintService.php` | 408 | statutory | `WorkingDayCalculator` | Awb 9:11 klachttermijn in weeks; the service already consults the calculator for working days |
| `lib/Service/DeadlinePauseService.php` | 93, 172 | statutory | engine calendar | Awb 4:5 and 4:15: the credited suspension and the unused remainder both move `endDateCurrent` |
| `lib/Service/DemoCaseloadReport.php` | 86 | neither | | a three day horizon in a demo report |
| `lib/Service/DemoCaseloadSeedDataService.php` | 343 | neither | | demo seed data |
| `lib/Service/Doorlooptijd/CaseEnricher.php` | 233 | business | engine calendar | the dashboard's derived expected end; it mirrors a statutory term, so it rolls with it or it contradicts it on screen |
| `lib/Service/Doorlooptijd/DeadlineComplianceCalculator.php` | 168 | neither | | month buckets for a compliance trend |
| `lib/Service/DsoCaseService.php` | 310 | statutory | `WorkingDayCalculator` | the Omgevingswet term; the day walk is already the calculator's |
| `lib/Service/DwangsomUitbetalingService.php` | 101 | statutory | engine calendar | Awb 4:17: `paymentDateLatest` is the date the dwangsom payment is late after |
| `lib/Service/Kcc/SlaCalculator.php` | 183, 218 | neither | `WorkingDayCalculator` | an intraday KCC service norm in seconds and minutes |
| `lib/Service/NoticeOfDefaultService.php` | 171 | statutory | engine calendar | Awb 4:17: the grace period whose end opens the dwangsom window |
| `lib/Service/ProcessMining/ThroughputTrendCalculator.php` | 109 | neither | | weekly buckets for a throughput trend |
| `lib/Service/ProcessMiningService.php` | 93, 96 | neither | | a twelve month reporting window |
| `lib/Service/Recycle/RetentionClocks.php` | 144 | neither | | a lawful-purpose retention counted in months (AVG art. 5.1e), where a weekend cannot move the answer; the same verdict `ArchivalNominationDeriver` carries for the Archiefwet side |
| `lib/Service/Lifecycle/SilenceCloseService.php` | 131 | neither | | an ADMINISTERED period of silence a case type declares, after which the product closes the case, and the date it announces. Not a term: nothing in the Awb counts sixty days of nobody answering, the number is a gemeente's own setting, and a weekend cannot move an answer to "has anything happened". Same verdict as `RetentionClocks` for the same reason |
| `lib/Service/QuickActionService.php` | 158 | statutory | engine calendar | Awb 9:11: the six week klacht decision term, written on intake from the KCC |
| `lib/Service/Status/StatusDwellService.php` | 135 | business | `WorkingDayCalculator` | how long a case has sat in one status, a service level and never a term. The BREACH is the engine's: `StatusDwellTimer` arms it in the engine's own `businessDays` unit over the calendar the organisation administers. The count held on the case is the local calculator's, because the engine exposes projection and no count between two dates, so the two can differ by a day on a custom calendar. Closing that needs a count operation in openregister `working-calendar-admin` |
| `lib/Service/Stuf/StufOutboundTransport.php` | 298 | neither | | not date arithmetic: `IJobList::add()` matched the `->add(` pattern |
| `lib/Service/Subsidie/BeschikkingService.php` | 82 | statutory | engine calendar | the bezwaartermijn of a subsidy beschikking |
| `lib/Service/Subsidie/BewijsstukService.php` | 136 | neither | | a record retention period in years |
| `lib/Service/Subsidie/SubsidieService.php` | 151 | statutory | engine calendar | the Awb decision term of a subsidy application |
| `lib/Service/Subsidie/TerugvorderingService.php` | 88, 101 | statutory | engine calendar | the bezwaartermijn and the payment term of a clawback decision |
| `lib/Service/Subsidie/TussenrapportageService.php` | 99 | statutory | engine calendar | the assessment term of an interim report, the date the applicant is answered by |
| `lib/Service/TermijnNotificationService.php` | 97 | neither | | not date arithmetic: `IJobList::add()` matched the `->add(` pattern |
| `lib/Service/TermijnTimerService.php` | 481 | neither | itself | not date arithmetic: the engine's `SlaCalculator::add()` matched the `->add(` pattern. This file IS the bridge; it asks the engine for the roll and computes no date of its own |
| `lib/Service/TermijnService.php` | 109 | statutory | allowlisted | `endDateCalculated`, owned by `terms-on-the-engine-calendar` task 1.3, which declares `deadlineDefinition.rollToWorkingDay` and applies it here |
| `lib/Service/WOODeadlineService.php` | 101, 172 | statutory | engine calendar | Woo art. 4.4: the decision term and its statutory extension |
| `lib/Service/WorkingDayCalculator.php` | 170, 204 | neither | itself | the calendar. It is the fallback the three sites use when the engine is absent, and the only holiday list dossiq is allowed to hold |

## Two rows added after the reading

`lib/BackgroundJob/AcknowledgementDispatchJob.php` and
`lib/Listener/AcknowledgementOnCreateListener.php` did not exist at
`16f00124a` and so are not in the 37 counted above. The change
`ontvangstbevestiging` added them, and both are `->add(` matches on
`IJobList`, not on a date. They carry the verdict `neither` for the reason
this file already names: two of the five patterns cannot be anchored to a
date type. The counts below describe the reading, not the table.

## What the table is not

It is not a defect list. Twenty of the 37 have the verdict `neither`, and
for each of them the reason is the point: saying once, in writing, that a
throughput bucket does not need a working day roll is what stops the next
reader running the same grep and reaching the same uncertainty.

Two rows deserve their own sentence.

- `lib/Service/Bezwaar/HearingSchedulePlanner.php` looks statutory and is
  not, in the sense this audit means. Awb 7:4 lid 2 gives a minimum of
  seven days between the inzage and the hearing. Algemene termijnenwet
  art. 1 lengthens a term by moving its end forward; applied to a floor
  counted backwards it would shorten the citizen's inzage, which is the
  opposite of what the Awt is for. The floor stays raw, deliberately.
- `lib/Service/TermijnService.php` is the one statutory file this change
  does not fix. It is the file `terms-on-the-engine-calendar` names as its
  motivating defect, and taking it here would edit another lane's open
  change. It is allowlisted with that change as its owner.
