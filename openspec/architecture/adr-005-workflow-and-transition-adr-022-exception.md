# ADR-005: The workflow and transition services are an ADR-022 documented exception

- **Status:** Accepted
- **Date:** 2026-09-11
- **Sunset:** 2027-01-31
- **Deciders:** Ruben van der Linde, dossiq architecture
- **Scope:** dossiq, case status machinery and the CMMN case plan
- **References:** hydra ADR-022 (apps consume OpenRegister abstractions), exception clause. Hydra gate 23, `or-abstraction-anti-patterns`, rule 5. Change `retire-cmmn-caseplanstate`. Canonical specs `status-transition-engine`, `case-status-machinery`, `case-bulk-status-transition`, `beschikking-generatie`.

## Context

ADR-022 gives the lifecycle and the workflow engine to OpenRegister. Gate 23
rule 5 enforces that on file name: anything called `*StateMachine*.php`,
`*StatusTransition*Service.php` or `*WorkflowEngine*.php`. It blocks from
2026-10-03.

Four dossiq files match. They are not one problem, and they do not retire
together.

`Cmmn/PlanItemStateMachine` is a straight duplicate. OpenRegister ships the CMMN
case layer: the same six-state plan-item table, sentries, cascade, fail-closed
authorization, rows with their own audit table, and routes on `/api/cases`. The
copy is the one to delete.

`StatusTransitionService` is not a duplicate, and calling it one would be wrong.
Its edge list lives in `workflowTemplate` objects per case type, so `case.status`
is a reference to a `statusType` row rather than an enum. OpenRegister's static
lifecycle mode needs an enum and cannot apply. Its graph mode derives moves from
sibling rows by order, with no per-edge guards, conditions, authorization or
actions. OpenRegister has no lifecycle whose edge list is read from a related
definition object. Its own source says so.

`BulkStatusTransitionService` wraps that engine for one to a hundred cases, with
a readiness preview and per-case failure isolation. It holds no map of its own.
OpenRegister has no bulk transition and no bulk available-actions, so there is
nothing to consume yet.

`StateMachineService` is the beschikking machine: six states, one back edge, and
immutability from `signed`. That shape is exactly what a declared lifecycle
validates on every save, so this one is a duplicate too.

## Decision

Per the ADR-022 exception clause, the gate suppresses these paths. Every
suppression is printed on every gate run with this ADR named beside it.

- `lib/Service/Cmmn/PlanItemStateMachine.php`
- `lib/Service/StateMachineService.php`
- `lib/Service/StatusTransitionService.php`
- `lib/Service/BulkStatusTransitionService.php`

## The gap this ADR records

Only one of the four has a change that retires it.

`retire-cmmn-caseplanstate` owns `Cmmn/PlanItemStateMachine`. It stands at zero
of sixteen tasks. Its order is fixed by data risk: project the blob, drain it
with a dry run and a strict mode, prove the migration is idempotent, and remove
the `casePlanState` property last, because OpenRegister strips undeclared
properties on the next save and removing the declaration while rows are
populated destroys them.

The other three have **no owning change at all**. That is the gap, and it is the
reason this ADR carries a shorter sunset than the tenant one.

- `StateMachineService` needs the beschikking lifecycle declared on the register,
  the immutability rule moved to a guard, and then the class deleted. No change
  proposes this yet.
- `StatusTransitionService` needs an abstraction OpenRegister does not have:
  a lifecycle whose edge list comes from a related definition object, carrying
  per-edge guards, authorization and actions. The archived
  `workflow-definitions-to-flow` change projects templates onto flows without
  removing the engine, so it is a staging step and not the retirement.
- `BulkStatusTransitionService` moves when the engine it wraps moves. The bulk
  endpoint itself is generic and belongs upstream, and should be proposed as an
  OpenRegister change now rather than waiting.

Writing the three missing changes is the work this ADR asks for. An exception
with no plan behind it is a permanent one.

## What this exception does not cover

The other local transition tables are not exempted, and the gate does not see
them either, which is worse. `ComplaintService`, `ConsultationService`,
`AdviceService`, `SubsidieService`, `AdvisoryCommitteeService` and
`InformatieobjectStatusLifecycle` each carry their own map by constant. They are
tracked in the `LocalStatusMachineryTest` allowlist, and that allowlist is the
census to shrink.

Eight dossiq schemas already declare a lifecycle. Nothing listens to the
transition event OpenRegister raises for them, so none of them fan out.

## Sunset

**2027-01-31.** On that date the gate stops honouring this ADR and all four
paths count again.

Two of the four can retire well before it. `Cmmn/PlanItemStateMachine` goes once
the drain report shows zero populated blobs. `StateMachineService` goes as soon
as the beschikking lifecycle is declared, and a request that moves a signed
beschikking back to draft is refused by OpenRegister rather than by dossiq.

## Status of the work

Nothing here is done. `retire-cmmn-caseplanstate` is at zero of sixteen, and the
other three files have no change to be at any point of. This ADR records a
scheduled migration and a gap in it. It is not a claim of progress.

## Next

Open the three missing changes, starting with the beschikking lifecycle, and
propose the bulk transition endpoint upstream in openregister.
