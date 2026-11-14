---
kind: code
depends_on: []
---

# Proposal: what-a-status-declares

## The rows this closes

**2.43**, area Case core, rated `no`: "Case status derived from what has
been recorded, not set by hand."

Source field, verbatim: `dossiq#2314, published as 2.37`.

Corpus batch file, `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
in ConductionNL/market-intelligence, the table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **2.43** | 2.37 | Case status derived from what has been recorded, not set by hand | no | unread |  |
```

The ledger note, verbatim:

> StateMachineService moves a case when somebody chooses a transition. Nothing computes the status from which forms and documents are present.

**2.46**, area Case core, rated `partial`: "Each status declares whether the
case waits on us or on someone else."

Source field, verbatim: `dossiq#2314, published as 2.40`.

The table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **2.46** | 2.40 | Each status declares whether the case waits on us or on someone else | partial | unread | corpus 2.25 |
```

The ledger note, verbatim:

> statusType.role is an administered enum including pending-info and stranded, rendered as Waiting for information and read by StatusTypeLookup and the shipped flow. It conflates waiting on the applicant with waiting on a third party, and no team count is built on it.

**8.25**, area Deadlines, rated `partial`: "Maximum dwell time per status,
breached apart from the case term."

Source field, verbatim: `dossiq#2314, published as 8.17`.

The table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **8.25** | 8.17 | Maximum dwell time per status, breached apart from the case term | partial | unread |  |
```

The ledger note, verbatim:

> StalledCaseDetector projects the earliest unreached milestone from the case start and reports a case past a grace period. No status carries a configured maximum time of its own.

**10.18**, area Reporting, rated `partial`: "Time spent in each status, held
on the case as a column and a filter."

Source field, verbatim: `dossiq#2314, published as 10.14`.

The table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **10.18** | 10.14 | Time spent in each status, held on the case as a column and a filter | partial | unread | corpus 10.11 |
```

The ledger note, verbatim:

> DwellTimeAnalyzer computes dwell per case and status visit and aggregates median, p90 and mean, charted on the process mining page. The number is not held on the case, so a handler cannot sort or filter a work list by it.

## What the competitor evidence is

None for any of the four. All four are among the 98 rows promoted under
decision D1, and the batch file puts it plainly: "Every competitor column is
`unread`, and none of them is `no`. ... `no` is a reading of a product
somebody opened, and filling these cells with it would fabricate thirty
readings per row."

Rows 2.46 and 10.18 carry cross-references, to corpus rows 2.25 and 10.11.
A cross-reference names a neighbouring question and is not a reading.

## Why

A status in dossiq is a name, an order, a colour and a checklist. Four
things a status has to say about itself are missing, and each of them is a
question somebody asks daily.

**What makes it true.** Statuses move because a person picked a transition.
Two of the most common ones are not judgments at all: a case is "complete"
when the form and the four documents are there, and it is "waiting on
advice" when an advice request is open. Asking a handler to set those by
hand means the status is right only as often as somebody remembers.

**Who we are waiting on.** `statusType.role` includes `pending-info` and
`stranded`, rendered as "Waiting for information". That conflates waiting on
the applicant with waiting on a third party, and those two have different
statutory consequences under the Awb: the first suspends the term, the
second does not. And no count is built on it, so a team lead cannot see how
much of the queue is actually theirs to move.

**How long is too long.** `StalledCaseDetector` projects the earliest
unreached milestone from the case start and reports a case that is past a
grace period. That is a case-level number. A case that spends nine weeks in
one status can be well inside its term and badly stuck, and nothing notices,
because no status carries a maximum of its own.

**How long it has been.** `DwellTimeAnalyzer` computes dwell per case and
per status visit and charts the median, the p90 and the mean on the process
mining page. The number reaches a manager and never reaches a handler,
because it is not on the case: there is nothing to sort a work list by and
nothing to filter on.

## What changes

- A status may declare the conditions under which it is true. Where it does,
  the system moves the case into it as the conditions become true, and the
  status is no longer offered as a hand-picked transition.
- A derivation that cannot fire says why, so a case that should be complete
  and is not shows the missing document rather than the handler guessing.
- `statusType` declares who the case is waiting on: us, the applicant, or a
  named third party. The applicant value and the third-party value are
  distinct, and the existing `role` values keep working.
- Counts per team and per queue are built on that declaration, so "what is
  actually ours to move" is a number.
- A status may declare a maximum dwell, counted on the organisation's
  working calendar, breaching on its own and separately from the case term.
- The dwell in the current status, and the total per status, are held on the
  case, so the work list sorts and filters on them. The process mining page
  keeps its aggregates and reads the same numbers.

## Ownership

dossiq builds all four. Under dossiq ADR-005 the transition engine is a
documented ADR-022 exception and stays dossiq's, so what a status declares
is dossiq's to specify.

Consumed:
- openregister `flow-business-timers` (shipped) for the dwell timer that
  breaches a maximum, so dossiq arms a timer rather than running a sweep;
- openregister `working-calendar-admin`, to be specified in openregister
  (register row 8.12), for the calendar the dwell counts on, which dossiq
  already consumes through `terms-on-the-engine-calendar` and
  `dwell-time-on-the-working-calendar`;
- openregister `lifecycle-declarative-conditions`, to be specified in
  openregister, for evaluating a declared condition. Until it lands dossiq
  evaluates the declaration itself, against the same vocabulary, so the move
  is a deletion later rather than a rewrite.

## ADRs

- Company ADR-022: the timer and the calendar are the platform's.
- Company ADR-031: what makes a status true, who it waits on and how long it
  may last are declared on the schema rather than written per status in PHP.
- Company ADR-038 for the requirement ids.
- dossiq ADR-005: the transition engine is the documented exception, and
  this change adds declarations to it rather than moving it.

## Size

M. Three declarations on `statusType` and one derived number held on the
case.

## The existing spec this extends

`status-transition-engine`, which carries REQ-STE-01 to REQ-STE-03 and
REQ-STE-11 to REQ-STE-13 canonically and REQ-STE-40 to REQ-STE-42 from
`status-capacity-limit`, and `doorlooptijd-dashboard`, whose dwell reporting
`dwell-time-on-the-working-calendar` is moving onto the working calendar.

## Out of scope

- The capacity of a status, which is `status-capacity-limit` REQ-STE-40 and
  counts cases rather than time.
- Which clock the process mining page counts on, which is
  `dwell-time-on-the-working-calendar`. This change holds the number on the
  case and takes the clock from there.
- The statutory term itself and its suspension, which are
  `termijnbewaking-op-engine-timers`.
- The public words a citizen reads for a status, which are
  `citizen-status-labels` REQ-CT-25.
