---
kind: code
depends_on: []
---

# Proposal: task-defaults-to-case-handler

Competitor gap register, row 3.8 "Auto-assign tasks to the case handler"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated partial, owner dossiq, size S. Re-read on 2026-09-13,
addendum row 3.8: the gap stays.

## Why

A task created by a transition goes to nobody unless the author named
someone. `lib/Service/Transitions/CreateTaskHandler.php` resolves
`assignee` through `lib/Service/AssigneeResolver.php`, which tries the
authored assignee, then the declared fallback, and answers `''` when neither
names anyone (lines 76 to 109). The flow node `DossiqAskPersonNode` uses the
same resolver. So every case type that did not author an assignee on every
action creates unassigned tasks, and the task's `taskAssigned` notification
goes nowhere.

The best competitor in the register: GZAC/Valtimo,
`backend/process-document/src/main/kotlin/com/ritense/processdocument/listener/CaseAssigneeTaskCreatedListener.kt`
(`_round2/compare/M1-functionality.md`).

## What changes

- `AssigneeResolver::resolve()` gains a third step: when the authored
  assignee and the fallback both name nobody, answer the case's `assignee`;
  when the case has none, the case's `assignedGroup`; only then `''`.
- The resolver logs the step it landed on, as it already does for the
  fallback.
- An action can opt out with `assignee: "none"`, for tasks that must stay
  unclaimed (a queue task).

## Ownership

dossiq builds it: which person a case's work goes to is case-type
configuration. It consumes OpenRegister's engine task (`remove-casetask`)
unchanged.

## ADRs

- Company ADR-031: the default is a declared rule of the action
  vocabulary, documented in the handler's config shape.
- Company ADR-023: the resolver names a principal; RBAC on the task stays
  OpenRegister's.

## Capabilities

- Modified: `task-management`: a task created on a case defaults to the
  case handler.

## Impact

`lib/Service/AssigneeResolver.php`, `lib/Service/Transitions/
CreateTaskHandler.php` (config doc only), `lib/Flow/DossiqAskPersonNode.php`
(inherits the default), unit tests.
