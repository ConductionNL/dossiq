---
kind: code
depends_on: []
---

# Proposal: phase-terms-and-the-internal-target

Round 4 discovery, cluster 18 "The term model: phases, chains, suspension
and the internal target" (`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Eleven candidates, four of
them `must`, eleven passers, nine driven, proving system Vikunja. Owner
dossiq, size M, depends on working calendars. Statutory: Awb 4:5 and Awb
4:14.

The register records why it carried nothing
(`procest/_gaps/gap-register.json`, `discovery.counts.uncarried_reason`,
cluster 18): "no change opened; integriq names the cluster as dossiq's
when it hands C-integrations-3 back".

## Why

dossiq has one clock per case. A gemeente runs four: the statutory term
the citizen is told about, the service norm a teamleider steers on, the
fase term inside it, and the pause the Awb allows.

Collapsing them into one field means a case that is on time for the
citizen and six weeks late for the team reads green, and a phase that has
eaten the whole term is invisible until the term itself expires.

## What is actually there

Read against `development` at `172d364f`, after
`terms-on-the-engine-calendar` landed (#2748, "every statutory term lands
on the administered calendar").

- `TermijnService`, `TermijnTimerService`, `DeadlinePauseService`,
  `DeadlineExtensionService`, `DeadlineEscalationService` and
  `DeadlineReportingService` exist. The mechanism is there.
- `caseType` already carries `suspensionAllowed`, `extensionAllowed` and
  `extensionPeriod`, and `lib/Service/CaseLifecycleService.php:114` and
  `:116` read the two booleans into `canSuspend` and `canExtend`. So the
  declaration is enforced.
- `extensionPeriod` is read nowhere but `lib/Repair/LoadDefaultZgwMappings.php`,
  which maps it to and from `verlengingstermijn`.
  `DeadlineExtensionService` computes `calculateDaysImpact()` at
  `lib/Service/DeadlineExtensionService.php:249` and never compares it to
  the declared period. The lane's clause is exactly right: "ours lets any
  case be extended by any amount and the Awb does not".
- `statusType` carries `name`, `description`, `caseType`, `order`,
  `isFinal`, `role`, `colour`, `hiddenInLists` and `checklist`. There is
  no term.
- `DeadlinePauseService::registerPauze()` and `resumeAfterPauze()` exist,
  and the letter that asks for the aanvulling is sent separately.

So the correction runs both ways. C-deadlines-18 is further along than
`partial` suggests, because the booleans are declared and enforced; what
is missing is only the number of days. And the gap under C-deadlines-12
is complete: a phase has an order and no clock at all.

## The candidates

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-deadlines-12 | must | partial | every phase carries its own term in days, beside the case's own term |
| C-deadlines-17 | must | partial | the case carries a planned end date beside its statutory term, and each is warned on separately |
| C-deadlines-18 | must | partial | the case type declares whether suspension and extension are possible, and for how many days |
| C-deadlines-20 | must | partial | the request for information sends the letter and suspends the term in one act |
| C-deadlines-1 | should | no | a case type's term is a fixed calendar end date instead of a lead time |
| C-deadlines-4 | should | partial | a progress percentage computed from the phases and the term, with a days-left count, in every list |
| C-deadlines-9 | should | no | an internal target runs beside the external term, clocked separately on the same case |
| C-deadlines-13 | should | no | one deadline is given to the whole chain and the product splits it over the steps |
| C-deadlines-21 | should | no | the team inbox is one administered object carrying the vocabularies and three separately clocked targets |
| C-deadlines-5 | could | partial | a record carries a planned start as well as an end |
| C-reporting-20 | should | partial | how old the open workload is right now, per state |

Evidence verbatim from the lane
(`_round4/discovery/candidates.json`):

- C-deadlines-12, `deadlines.tsv:5`: "xxllnc-zaken: Case type > Fasen
  (case-type-editor-anatomy.md)". Its clause: "an ontvankelijkheidstoets
  has its own two weeks inside an eight-week term". The lane marks it
  `dossiq-only 8.25`.
- C-deadlines-17, `deadlines.tsv:11`: "dimpact-zac: Case detail
  (zaak-management/spec.md)". The lane's note: "Both say the case has two
  clocks, a working plan and a legal one, and that the product tells them
  apart. lanes disagree, rated must, could".
- C-deadlines-18, `deadlines.tsv:17`: "xxllnc-zaken: Case type >
  Documentatie (case-type-editor-anatomy.md)".
- C-deadlines-20, `deadlines.tsv:22`: "dimpact-zac: Task, aanvullende
  informatie (docs/user-manual-features.md)". Its clause: "Awb 4:5 says
  the clock stops when you ask, and joining the ask to the stop is what
  makes it correct".
- C-deadlines-9, `deadlines.tsv:13`: "glpi: Service levels (Setup,
  Service levels, front/slalevel.php, olalevel.php, src/SLA.php,
  src/OLA.php, src/LevelAgreement.php)". The lane's note: "One names it an
  OLA beside the SLA, the other the time each team held the case. Same
  second clock".
- C-deadlines-13, `deadlines.tsv:20`: "opencase: Document detail Workflow
  (DocumentDetail-Workflow.md)". Its clause: "it is the only mechanism in
  the corpus that derives a step deadline from a case deadline".
- C-deadlines-21, `deadlines.tsv:15`: "znuny: Queues, Types, Priorities,
  States, Services, SLAs (AdminQueue.pm, AdminType.pm, AdminPriority.pm,
  AdminState.pm, AdminService.pm, AdminSLA.pm, sla carrying three clocks
  over nine calendars)".
- C-deadlines-1, `deadlines.tsv:27`: "xxllnc-zaken: Case type > Algemeen
  (case-type-editor-anatomy.md)". Its clause: "a subsidy round closes on
  a date, not N days after each application".
- C-deadlines-4, `deadlines.tsv:28`: "xxllnc-zaken: Case data model
  (case-management/spec.md)".
- C-deadlines-5, `deadlines.tsv:26`: "vikunja: Task, Start date and End
  date beside the due date (menu-tree.md)".
- C-reporting-20, `reporting.tsv:26`: "youtrack: Reports > Average Issue
  Age ('the average length of time that issues spend in specific states…
  monitor your response time')". The lane's note: "Not the same as
  time-in-status on finished cases: this reads the work still standing".

**D6 was answered relevance-led**, so all four `must` candidates enter
whatever their passer count, and three of the four carry a single driven
passer. **D17** does not reach this cluster.

## What changes

- A phase carries its own term in days, clocked on the same calendar as
  the case term, and a phase that is over its term is visible before the
  case is.
- A case carries a planned end date beside its statutory term. The two are
  warned on separately and never collapse into one field.
- A case type declares a suspension length and an extension length in
  days, and a suspension or an extension beyond the declared length is
  refused with the rule named.
- Asking the applicant for something sends the letter and suspends the
  term in one act, and resuming is one act too.
- A case type's term is either a lead time or a fixed calendar date.
- An internal target runs beside the external term, clocked separately,
  reported separately, and never shown to the citizen.
- A chain term is declared once and split over its steps, with what is
  left recomputed when a step finishes early or late.
- A case carries a planned start beside its end.
- The age of the open workload, per status, is answerable now rather than
  from closed cases.

## Ownership

dossiq owns the term model, the phase term, the internal target, the
suspension and extension lengths, and the act that joins the letter to the
pause. They are dossiq's schemas and dossiq's services.

What dossiq consumes:

| half | app | artefact |
|---|---|---|
| the working calendar every clock runs on | openregister | `working-calendar-admin`, `end-date-roll-on-the-calendar`, `calendar-time-zone`, consumed through dossiq `terms-on-the-engine-calendar` (shipped, #2748) and `counting-mode-per-term` |
| the timer that fires when a term passes | openregister | `flow-business-timers` and `SlaCalculator`, shipped, consumed by `termijnbewaking-op-engine-timers` |
| the progress and days-left column in a list | nextcloud-vue | `index-columns-per-scope`, the change the register names for row 11.9 |
| the aggregation behind the age of the open workload | openregister | the aggregations endpoint, shipped |

### Needs a change in nextcloud-vue

`index-columns-per-scope` is named by the register for row 11.9 and
covers which columns a list shows. A computed progress column with a
days-left count is a column type it does not carry. A follow-up lane
should either extend it or open a sibling. Until then dossiq computes the
value and the case page shows it, and only the list column waits.

## ADRs

- Company ADR-011: search OpenRegister before implementing a utility. Every
  new clock uses `SlaCalculator` and the administered calendar; dossiq
  adds no date arithmetic of its own.
- Company ADR-102: config absence fails closed with a status. A phase term
  whose calendar cannot be resolved refuses rather than falling back to
  calendar days.
- Company ADR-050: the error envelope is `{message, error}`. A refused
  extension names its rule in `error`, the shape
  `refusals-carry-a-status` builds.

## Capabilities

- Modified: `termijn-binding`: a phase has a term, a case has a planned
  end and a planned start, a term is a lead time or a date, and an
  internal target runs beside the external one.
- Modified: `termijn-pause-extension`: the declared lengths are enforced,
  and asking and suspending are one act.
- Modified: `termijn-reporting`: progress and days left are computed, and
  the open workload has an age per status.

## Impact

`lib/Service/TermijnService.php`, `TermijnTimerService.php`,
`DeadlinePauseService.php`, `DeadlineExtensionService.php`,
`DeadlineReportingService.php`, the `statusType` and `caseType` schemas,
`src/views/doorlooptijd/`, Dutch and English strings.

## Where this change disagrees with the register

- **C-deadlines-18 is nearer done than `partial` reads.**
  `suspensionAllowed` and `extensionAllowed` are declared and enforced at
  `CaseLifecycleService.php:114` and `:116`. Only the length in days is
  missing, so this is one task rather than a mechanism.
- **C-deadlines-21 is carried in half.** Its three separately clocked
  targets are the same second clock as C-deadlines-9 and are built here.
  Its other half, bundling the vocabularies onto one queue object, is
  declined: dossiq holds its vocabularies on the case type and in
  OpenRegister code lists, which is one place rather than one object, and
  moving them onto a queue would undo `code-lists-from-concepts`. The
  lane says so itself: "Its distinctive half is the bundling, not the
  second clock".

## Out of scope

- The working calendar itself. openregister, and dossiq's
  `terms-on-the-engine-calendar`.
- The counting mode per term. dossiq `counting-mode-per-term`.
- Escalation and the once-a-day guard. Cluster 42 and dossiq
  `termijn-escalation`.
- The dependent term following a predecessor. dossiq
  `dependent-term-follows-predecessor`.
