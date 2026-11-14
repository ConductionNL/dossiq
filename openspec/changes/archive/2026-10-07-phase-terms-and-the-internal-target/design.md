# Design: phase-terms-and-the-internal-target

## D-1. Every clock is a term instance, not a field on the case

dossiq already has `TermijnService` and a term instance with a start, an
end and a pause history. A phase term, a planned end and an internal
target are three more of those, not three more date columns.

One shape means one calendar, one pause rule, one escalation path and one
report. Three date columns on `case` would mean three places that compute
a working day, which is the duplication ADR-011 exists to stop.

Each instance carries what it is: statutory, planned, internal or phase.
That kind is what decides who is warned, who may see it, and whether the
citizen is ever told about it.

## D-2. The statutory term and the service norm never collapse

Dimpact ZAC proves the pair (`_round4/discovery/candidates.json`,
C-deadlines-17, `deadlines.tsv:11`) and the lane's note says what it is
for: "Both say the case has two clocks, a working plan and a legal one,
and that the product tells them apart".

So they are warned on separately, reported separately, and displayed
distinctly. A case that is late against its plan and on time against the
Awb must read as exactly that, because those two facts lead to different
actions.

The internal target of C-deadlines-9 is the same idea one level up: a team
target the citizen never sees. GLPI calls it an OLA beside the SLA. It is
a term instance of kind internal, and it is refused to any citizen-facing
surface by the same visibility flag `timeline-entries-default-internal`
uses.

## D-3. A phase term is declared on the status type and clocked on entry

`statusType` has `order` and `isFinal` and no clock. The term is declared
there, in days, and the instance starts when the case enters that phase
and stops when it leaves.

A phase term is not allowed to be a second source of truth for the case
term. The case term is bound at creation and stays bound. A phase running
over eats into the case term and says so; it never extends it.

## D-4. A chain term is split, and the split is recomputed

OpenCase is the only system in the corpus that derives a step deadline
from a case deadline (`deadlines.tsv:20`). The design follows it: the
chain carries the term, the steps carry shares of it, and the remaining
shares are recomputed when a step ends early or late.

Recomputation never moves the chain's own end. A chain whose steps have
eaten it says the remaining steps have zero days, which is a true and
actionable statement, rather than silently moving the promise.

## D-5. A lead time and a fixed date are one declaration with two forms

xxllnc declares the term on the case type and it may be a date
(`deadlines.tsv:27`). Its clause names the case: "a subsidy round closes
on a date, not N days after each application".

So `caseType.processingDeadline` grows a form: a lead time in days, or a
fixed calendar date. Everything downstream reads a bound end date and does
not care which form produced it. A fixed date already past at case
creation binds an already-expired term, visibly, rather than refusing the
case: a late application to a closed round is a real case with a real
answer.

## D-6. The declared length is a ceiling that refuses, and names its rule

`caseType.extensionPeriod` exists and nothing reads it.
`DeadlineExtensionService::calculateDaysImpact()` computes the move and
never compares it. That is the fail-open shape
`refusals-carry-a-status` was opened for, so the fix takes its form: a
typed refusal, a 4xx, and the rule named in `error` per ADR-050.

A suspension length is added beside it, because Awb 4:5 bounds the pause
as well as the extension.

## D-7. Asking and suspending are one act because two acts drift

`DeadlinePauseService::registerPauze()` and the letter are two calls
today, so a handler can send the letter and forget the pause, or pause and
never write. Dimpact joins them (`deadlines.tsv:22`).

So there is one act: it sends the request, records what was asked for,
suspends the term, and records all three together. Resuming is the
mirror, and receiving the aanvulling is what triggers it.

If the letter fails to send, the term is not suspended. A suspended clock
with no letter is a case where the citizen was never asked, and that is
worse than an unsuspended one.

## D-8. Progress is computed, never stored

xxllnc shows a progress figure per row (`deadlines.tsv:28`). A stored
percentage is wrong the moment a term moves, and terms move on every
pause, extension and calendar change.

So progress is derived from the phases completed and the term consumed,
at read time, beside the days-left count. The case page renders it now.
The list column waits on nextcloud-vue, and the proposal says so rather
than shipping a column that computes in the browser and disagrees with
the case page.

## D-9. The age of the open workload is an aggregation, not a report job

YouTrack reads the work still standing, not the work finished
(`reporting.tsv:26`). `ProcessMiningController` and
`BottleneckDetectionJob` compute durations over closed cases, which is a
different number.

The age of what is open is one aggregation over open cases grouped by
status, answered by OpenRegister. dossiq adds no store and no nightly job.
Note for whoever builds it: the aggregations endpoint spells its filters
`filter[x]` and drops a bare key, unlike the objects endpoint, so the
same query written the other way returns the whole register with a
confident wrong number.
