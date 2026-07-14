# Design: process-mining-bottlenecks

## Data source: `statusRecord`, not a new event log

Procest already writes exactly one `statusRecord` object per case status
transition, via `StatusTransitionService::writeStatusRecord()` (called from
both `execute()` and `executeFreeForm()`). Each row carries:

- `case` — the case UUID
- `statusType` — the **target** status (the status the case transitioned
  *into*)
- `fromStatus` — the **prior** status (present on every transition except a
  case's very first status assignment)
- `transitionLabel`, `description`, `evaluatedGuards`, `dispatchedActions`,
  `noWorkflowTemplate` — transition metadata not used by this report
- a creation timestamp — either a flattened `createdAt` key or
  OpenRegister's `@self.created` metadata, depending on serialisation path
  (`StatusTransitionService::replay()` already defends against both: `$left['createdAt'] ?? $left['@self']['createdAt']`
  — `ProcessMiningService::extractTimestamp()` follows the same defensive
  pattern, also checking `@self.createdAt` as a third fallback).

`StatusTransitionService::replay(string $caseId)` already reads this chain
back for **one** case, sorted chronologically, to power the case-detail
"Change history" sidebar tab (`retire-status-history-page`, archived
2026-06/07 — confirmed the standalone `StatusRecords` page was retired but
the underlying register and per-case tab stayed as the canonical surface).

`ProcessMiningService` reads the **same register at population scale**: no
`case` filter, `_limit: 10000`, then groups + sorts in-memory per case. This
was chosen over adding a `case IN (...)` filter to `SearchesObjects` (which
only supports single-value equality filters today, per its docblock) —
a population-scale process-mining report legitimately needs the whole
history, and 10k rows is a defensible one-shot read for a municipal case
volume. If a tenant's `statusRecord` volume outgrows this in practice, the
natural next step is a dedicated DB-side aggregation query (the
`KpiAggregationService` pattern) — deferred until there's a concrete
performance signal, since it would trade the current pure/testable
computation for raw SQL.

## Why per-status dwell time is a two-boundary interval, not the record itself

A `statusRecord` marks the moment a case *entered* a status. The time the
case *dwelt* in that status is the gap until the **next** record for the
same case (or, for the still-current status, until now/the case's
`endDate`). `ProcessMiningService::computeDwellIntervals()` therefore walks
each case's sorted record list pairwise:

- **Closed case, not the last status**: exit boundary = next record's
  timestamp.
- **Closed case, last status**: exit boundary = `case.endDate` (mirrors
  `DoorlooptijdService`'s `isOpen = (endDate === null)` convention).
- **Open case, last status**: exit boundary = "now" (the report's
  computation time) — this is the load-bearing "current status" case the
  task brief calls out. An open case's current status is genuinely
  still-accumulating dwell time; excluding it would under-count exactly the
  cases most worth flagging as stuck.
- **Zero-duration**: two records with an identical timestamp (an immediate
  automatic transition) yield a `0.0`-hour interval — included, not
  filtered out, since a status that's always skipped instantly is
  legitimately "not a bottleneck," and silently dropping it would bias the
  median upward for no reason.
- **Missing history**: a case with zero `statusRecord` rows contributes no
  intervals (skipped, not an error) — this happens for cases created before
  the status-transition engine shipped, or any case whose first transition
  hasn't landed yet.

**Period window** (`from`/`to`) filters on the interval's **entry**
timestamp, not its exit — a status entered on day 1 of the window and
exited on day 2 (still inside the window) is included with its full
duration; a status entered before the window even if it's still open during
the window is excluded, since attributing that dwell time to *this* period
would double-count it across adjacent report runs.

## Grouping by case type, not one flat report

The task brief asks for metrics "per case type": different case types have
structurally different status sets (a Wob request and a subsidy case don't
share a workflow), so a single flat bottleneck ranking across all case
types would be dominated by whichever case type has the most volume rather
than surfacing each workflow's own worst status. `getReport()` therefore
groups cases by `case.caseType` (resolved via `caseType.id`, mirroring
`DoorlooptijdService::enrichCases()`'s id-or-slug lookup) and computes dwell
stats / bottleneck ranking / transition matrix / rework % independently per
group, returned as a `caseTypes[]` array sorted by case volume descending.
The `caseType` query filter, when given, simply narrows `loadCases()` to one
type up front (same filter shape `DoorlooptijdService::loadCases()` uses) —
the rest of the pipeline is unchanged, it just operates on a
single-case-type group.

Throughput trend (cases closed per week) is reported once, globally scoped
to whatever case set is in play (all case types, or the one filtered type) —
splitting it per case type as well was considered but rejected for v1: the
dashboard's KPI/chart real estate already carries four other per-case-type
breakdowns, and weekly throughput read as a blended trend is still the
useful "is the tenant keeping up" signal a coordinator wants first. A
per-case-type throughput breakdown is listed as a follow-up.

## Rework detection: revisit, not "any backward transition"

A workflow can legitimately have branches that aren't linear (parallel
review steps, conditional routing) — flagging every non-forward-looking
transition as "rework" using the case type's declared status *order* would
false-positive on those. Instead, `computeCaseTransitions()` tracks the set
of statuses a case has **already visited** as it walks its own chronological
`statusRecord` chain; a transition is flagged as rework precisely when its
target status is already in that visited set — i.e. the case is genuinely
going back to somewhere it has already been, regardless of what the
case type's nominal status order says. This needs no `caseType.statusTypes`
ordering data at all, keeping the detection pure and testable against a
bare list of `{statusType}` records.

The transition matrix aggregates `(from, to)` pairs across every case in a
group; a given pair can have some rework and some non-rework instances (the
*first* A→B is normal, a *later* A→B after the case already left B once is
rework) — the matrix therefore carries both `count` and `reworkCount` per
pair, and the group's overall `reworkPercent` is `reworkCount / count`
summed across all pairs, not averaged per-pair (so a high-volume rework
loop weighs more than a rare one).

## Auth: same gate as `Iv3ReportController`, not `DoorlooptijdController`

`DoorlooptijdController` and `StatusTransitionController` both use "any
authenticated user" gating — appropriate for per-case or aggregate-KPI
views a caseworker also has legitimate reason to see. A process-mining
report is structurally different: it exposes the whole tenant's workflow
performance (which statuses are bottlenecks, how much rework is happening),
information a coordinator/controller role reviews, not a caseworker's daily
view. `Iv3ReportController` (`2026-07-13-iv3-case-cost-reporting`) already
established the right gate shape for this class of report — `IGroupManager`
allow-list (`controllers`/`beheerders`/`admin`) plus an `isAdmin()`
fallback — reused verbatim (`ALLOWED_GROUPS`, `ensureAllowed()`,
`isAllowed()`) rather than inventing a parallel "coordinator" concept.

## Frontend: `type: "custom"` + `component`, not the dashboard/widget/slots page

`DoorlooptijdDashboard` uses an older manifest pattern (`type: "dashboard"`
page + a `config.widgets[]`/`config.layout[]` grid + a `slots` map resolving
one `type: "custom"` widget to a component). The two most recent report
dashboards (`TermijnDashboard`, `Iv3ReportDashboard`) instead use the
simpler `type: "custom"` page with a direct `component` field — no grid
indirection needed for a single full-page custom view. `ProcessMiningDashboard`
follows the newer, simpler pattern (confirmed against `origin/development`
HEAD, not the stale local checkout this change was initially scoped
against — see the proposal's verification note).

Every visualisation is an existing nc-vue leaf: `CnKpiGrid`/`CnStatsBlock`
for the four headline tiles, `CnChartWidget` (bar) for dwell-time-by-status,
`CnChartWidget` (line) for the weekly throughput trend. The bottleneck
ranking table is a plain hand-rolled `<table>` — the same convention
`TermijnDashboard.vue`'s quarterly-report table and
`Iv3ReportDashboard.vue`'s per-taakveld table already use for ad-hoc
computed row shapes that don't correspond to an OpenRegister schema (no
nc-vue table leaf takes an arbitrary computed-row array; `CnDataTable`/the
object-table widget are schema/register-bound). No new chart or table
component is introduced.

## Test-fixture fix: `FakeTermijnStore` didn't strip pagination keys

`tests/Unit/Fixtures/FakeTermijnStore.php` is the shared in-memory
`ObjectService` fake several service test suites reuse (introduced for
`TermijnReportingServiceTest`). Its `findObjects()` treated every key in the
`$filters` array — including OpenRegister's `_limit`/`_offset` pagination
keys — as an object-field equality filter. `ProcessMiningService`'s load
methods pass `_limit` (mirroring `DoorlooptijdService`'s own `_limit: 1000`
convention), so under the unmodified fake, `['_limit' => 2000]` would
filter every row's nonexistent `_limit` field against `2000` and return an
empty result — passing tests for the wrong reason (the real
`SearchesObjects`/OpenRegister bridge documents `_limit`/`_offset` as
pagination keys "passed straight through," never treated as filters). Fixed
by stripping both keys before filtering; verified the full 1302-test
PHPUnit suite stays green (no existing test relied on the old, wrong
behaviour, since none of them pass `_limit`).
