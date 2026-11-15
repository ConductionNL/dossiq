---
kind: code
depends_on: [termijnbewaking-op-engine-timers]
---

# Proposal: term-configuration-beyond-the-case-type

## The rows this closes

**8.24**, area Deadlines, rated `partial`: "First-response term per case
type, with the size of the overrun stored."

Source field, verbatim: `dossiq#2314, published as 8.16`.

Corpus batch file, `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
in ConductionNL/market-intelligence, the table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **8.24** | 8.16 | First-response term per case type, with the size of the overrun stored | partial | unread |  |
```

The ledger note, verbatim:

> ComplaintService computes an acknowledgement deadline from a private constant, for complaints and nothing else. Nothing measures whether it was met and nothing stores how late we were.

**8.29**, area Deadlines, rated `no`: "Lead time resolved from the
organisation, the service and the priority."

Source field, verbatim: `dossiq#2314, published as 8.21`.

The table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **8.29** | 8.21 | Lead time resolved from the organisation, the service and the priority | no | unread | corpus 8.1 |
```

The ledger note, verbatim:

> Row 8.1 puts the term on the case type, so one shared case type cannot carry different terms for different participating municipalities. This is the gemeenschappelijke-regeling shape.

**8.30**, area Deadlines, rated `partial`: "Term clock running only in named
statuses, with thresholds as a percentage."

Source field, verbatim: `dossiq#2314, published as 8.22`.

The table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **8.30** | 8.22 | Term clock running only in named statuses, with thresholds as a percentage | partial | unread | corpus 8.14 |
```

The ledger note, verbatim:

> DeadlinePauseService and DeadlineEscalationService do this imperatively, with thresholds in days rather than as a share of the term. Nothing declares which statuses the clock runs in.

## What the competitor evidence is

None for any of the three. All three are among the 98 rows promoted under
decision D1, whose batch file states: "Every competitor column is `unread`,
and none of them is `no`. ... `no` is a reading of a product somebody
opened, and filling these cells with it would fabricate thirty readings per
row."

Rows 8.29 and 8.30 carry cross-references to corpus rows 8.1 and 8.14. A
cross-reference names a neighbouring question and is not a reading.

## Why

Every term dossiq knows is one number on one case type, and three ordinary
situations do not fit that shape.

**The first answer is a term too.** A complaint gets an acknowledgement, and
`ComplaintService` computes its deadline from a private constant. No other
case type has one, so the promise every service desk makes, that you hear
from us within N days, exists for complaints alone. And nothing measures it:
whether it was met is not recorded and how late it was is not stored, so the
one number a service manager needs, how far we miss by, cannot be reported.

**One case type, several municipalities.** A gemeenschappelijke regeling
runs one case type for five municipalities, and the participating
municipalities have agreed different service norms. The term is on the case
type, so either the norms are wrong or the case type is duplicated five
times and drifts. The same shape appears inside one organisation the moment
a service is offered at two levels, and again once priority is derived and
an urgent case ought to promise less time.

**The clock runs when the case is not ours to move.** `DeadlinePauseService`
and `DeadlineEscalationService` do their work imperatively, and the
thresholds are in days: fourteen, seven, two, nought. On a six-week term
fourteen days is a third of it and on a twenty-six-week term it is a
formality. Nothing declares which statuses the clock runs in, so a case
sitting with an external adviser burns the same clock as one on a handler's
desk.

## What changes

- A first-response term per case type, declared like any other term. Meeting
  it is recorded on the case, and so is the overrun: not a flag, the size of
  it, so "we were late on eleven cases by an average of three days" is a
  query.
- A term resolves from the organisation, the service and the priority, in
  that order, falling back to the case type's own term when nothing more
  specific is declared. One case type then serves five municipalities with
  five norms and no duplication.
- Which resolution a case's term came from is recorded on the case, so a
  disputed date can be explained without reading the configuration as it is
  today.
- A term declares the statuses its clock runs in. In any other status the
  clock is stopped, through the engine's own suspend and resume rather than
  a second mechanism.
- Escalation thresholds may be a share of the term as well as a number of
  days. A threshold at 25 per cent of a six-week term and of a twenty-six
  week term means the same thing to the handler and different dates to the
  timer, which is the point.

## Ownership

dossiq builds the declarations and the resolution. What a term is under the
Awb, and which norm applies to which municipality, are case administration
and dossiq's.

Consumed:
- openregister `flow-business-timers` (shipped) for the clock, the suspend
  and resume and the escalation ladder. A percentage threshold is resolved
  to a date when the timer is armed, so the engine's ladder stays the ladder
  and dossiq adds no second escalation, exactly as
  `termijnbewaking-op-engine-timers` REQ-TOT-003 requires;
- openregister organisation (shipped) for the organisation a term resolves
  from, which dossiq reaches through `tenancy-onto-openregister-organisation`;
- openregister `working-calendar-admin`, to be specified in openregister
  (register row 8.12), for the calendar the terms count on, consumed through
  dossiq's `terms-on-the-engine-calendar`;
- dossiq `case-priority-impact-urgency` (open) for the priority a lead time
  resolves from. This change reads it and does not restate it.

## ADRs

- Company ADR-022: the clock, the suspension and the ladder are the
  platform's.
- Company ADR-031: the term, its resolution order and its running statuses
  are declared on the definition, not computed in a service per case type.
- Company ADR-038 for the requirement ids.
- dossiq ADR-005: the term services stay dossiq's as the documented
  exception until the engine carries the statutory rules, so these
  declarations are dossiq's to write.

## Size

M. Three declarations on `deadlineDefinition` and one resolution order.

## The existing spec this extends

`termijnbewaking-schemas`, which carries REQ-TERM-013 from
`counting-mode-per-term` and REQ-TERM-018 to REQ-TERM-020 from
`every-term-on-the-engine-calendar`.

## Out of scope

- Whether a term counts calendar or working days, which is
  `counting-mode-per-term` REQ-TERM-013.
- Whether a statutory path reaches the calendar at all, which is
  `every-term-on-the-engine-calendar` REQ-TERM-018.
- The move of the clocks onto engine timers, which is
  `termijnbewaking-op-engine-timers` and which this change depends on rather
  than repeats.
- The maximum time a case may spend in one status, which is
  `what-a-status-declares` REQ-SDC-03. That is a status limit and this is
  the case's term.
