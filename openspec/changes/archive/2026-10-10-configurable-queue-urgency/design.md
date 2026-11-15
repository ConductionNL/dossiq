# Design: configurable-queue-urgency

## Context

`WorkQueueService::scoreItem()` is the one urgency rule dossiq has. Two
callers use it: `computeQueue()` behind `GET /api/work-queue` (the My Work
pills), and `Queue\QueueOrdering` behind the personal queue (without the age
part). `PersonalQueueService`, `DailyDigestComposer` and
`src/views/queue/PersonalQueueView.vue` read the `tier` key it answers.

## Decisions

### D-1. The score, with the defaults reproducing today

```
score = tierBase[deadlineTier] - workingDaysLeft
      + priorityWeight * priorityPoints[priority]
      + idleWeight * min(idleDays, 60)
```

- `tierBase` stays 1000 / 750 / 500 / 250 for overdue / critical / warning /
  normal, and a case with no deadline stays `normal` with no deadline part.
- `priorityPoints`: urgent 3, high 2, normal 1, low 0, anything else 1.
  The default `priorityWeight` of 10 gives today's 30 / 20 / 10 / 0.
- `idleWeight` defaults to 0.5, today's age weight. The cap of 60 days stays a
  constant: it is what keeps the tier in charge (below).

**The tier always dominates.** The weights are bounded so the priority and idle
parts together stay under 250 points: `priorityWeight` 0 to 50 (at most 150),
`idleWeight` 0 to 1.5 (at most 90). With the thresholds bounded too (D-2), an
urgent case lying still for months never outranks a low-priority case one tier
up. An administrator tunes the order WITHIN a tier; the tier is the term, and
the term is the law.

### D-2. Thresholds: admin default, case-type override, new fields

| Setting | IAppConfig key | Default | Bounds |
|---|---|---|---|
| Critical from (working days left) | `queue_critical_days` | 3 | 0 to 60 |
| Warning from (working days left) | `queue_warning_days` | 7 | 0 to 120 |
| Priority weight | `queue_priority_weight` | 10 | 0 to 50 |
| Idle-time weight | `queue_idle_weight` | 0.5 | 0 to 1.5 |

A stored value that does not parse or falls outside its bounds reads as the
default (out of bounds is clamped). The queue never fails on a setting: a
broken setting is an ordering an administrator can fix, a 500 is a page
nobody can use. A warning threshold below the critical one reads as equal to
it, which means the type has no Bijna band.

The case type overrides the two thresholds with two new optional integer
fields, `queueCriticalDays` and `queueWarningDays`, same bounds. Each falls
back to the admin default on its own.

**Why not `statutoryWarningDays` / `plannedWarningDays`.** They fit the shape
(working days before an end) but not the meaning. Each names a warning on ONE
specific term: the statutory end, or the planned end, and the schema text says
"leave it empty and the escalation ladder decides". The queue's deadline is the
nearest running term instance of any kind, falling back to `case.deadline`.
Reusing them would make one field steer two behaviours (a warning notification
on the statutory term, and the pill on whatever term is nearest), and the term
engine lane that is reworking terms in parallel may well wire them to the
escalation ladder. Two fields that say what they do are cheaper than one that
means two things.

The thresholds are looked up once per case type per queue computation.

### D-3. Lying still: days since the last activity

`idleDays` = calendar days from the last activity to today, where the last
activity is the LATEST of:

- `@self.updated`, the moment OpenRegister last saved the case (what
  `StalledCasesWidget` means by activity);
- the newest entry `at` in the case journal (`case.activity`, read through the
  existing `Lifecycle\CaseJournal::entries()`).

No usable moment falls back to `startDate`; a moment in the future counts as
today. Calendar days, not working days: "stil ligt" is read off a calendar,
and it keeps this change clear of `businessDaysBetween`, which another lane
owns.

`SilenceCloseService::lastActivity()` reads the journal FIRST and only falls
back to `@self.updated`, deliberately: its own warning write touches the case,
and counting that would reset the very clock it watches. The queue writes
nothing to the case, so it takes the latest of both.

Tasks have no idle part (their reference date stays null), as today. The
personal queue (`QueueOrdering`) passes no reference date either and does not
know the case type of an item, so it scores with the admin thresholds and
weights and no idle part.

### D-4. Urgentie sorts by the score, ranked on the server

Two options were weighed.

**A materialised score field on the case, rejected.** The score moves every
day without anybody touching the case (the days left shrink, the idle days
grow), so the field needs a nightly write to every open case. That write bumps
`@self.updated`, which IS the idle signal of D-3, so the score would erase its
own input. It would also write an audit trail entry and fire every update
notification and webhook on every open case, every night.

**Server-side ranking, chosen.** `GET /api/work-queue` already loads and
scores every open case of the reader. It now:

- reads them with the same filters My Work's list uses (`assignee`,
  `statusHiddenInLists: false`, `isDraft: false`) and an explicit `_limit` of
  1000, instead of OpenRegister's default page, which silently dropped every
  case past the first page from the scoring;
- carries the case row on each case item (`case`), so the list can render the
  card from the ranked answer.

My Work in Urgentie mode hands CnIndexPage the ranked rows (`:objects`), so the
order on screen is the server's. CnIndexPage cannot reorder rows it fetched
itself, so a self-fetch cannot carry an order that is not a stored field. The
search box and the sidebar's filters work on that ranked set in the browser:
the set is exactly the reader's open cases, already in hand. Nieuwste keeps the
self-fetching index unchanged.

If the work queue cannot be computed, Urgentie falls back to the self-fetching
index ordered by deadline and says so in one line, rather than showing an empty
list.

### D-5. Names

| Was | Becomes |
|---|---|
| `tier` (wire key) | `deadlineTier` |
| `TIER_*`, `tierFor()` | `DEADLINE_TIER_*`, `deadlineTierFor()` |
| `scoreBreakdown.age` | `scoreBreakdown.idle` |
| `urgencyChipClass()` | `deadlineTierPillClass()` |

The tier values stay `overdue`, `critical`, `warning`, `normal`. The response
also carries `idleDays`. The user-facing word stays "urgentie": the sort button
and the admin section say urgency, the pill says what the term is doing.

Pill labels, English source and Dutch: Late / Te laat, Critical / Kritiek,
Soon / Bijna, Normal / Normaal.

## Risks

- **Overlap with the term engine lane.** It changes `businessDaysBetween` and
  `nearestActiveTermDeadline` in the same file. This change does not edit
  either method, merges development in often and names the overlap in its PR.
- **Payload size.** Carrying the case row on each item grows the response.
  A handler's open queue is tens of cases; the cap is 1000.
- **Browser-side filtering in Urgentie mode** covers the reader's own open
  cases only, which is the whole list in that mode. Facet counts are computed
  from that set.
