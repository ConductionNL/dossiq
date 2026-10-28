# Proposal: family-plan-writes-check-the-case

## Why

SECURITY. `SociaalDomeinPlanController` is `#[NoAdminRequired]` on every method and its header says the plan's writes are
guarded by the case the plan belongs to, but no write checked anything: any signed-in user could save or close a goal,
save an intervention or record a review on any family's plan by its id (`saveGoal`, `closeGoal`, `saveIntervention`,
`recordReview`). A family plan is the most sensitive record dossiq holds. A write naming an existing goal or
intervention by `id` could also rewrite another family's record through a plan the caller may change.

## What changes

- Every plan write asks `CaseAccessGuard::hasCaseMutationAccess()` for the plan's case (`gezinsplan.caseId`) BEFORE
  the service writes anything, as `CaseGoalController` does for a case's own goals.
- `closeGoal` checks the goal's case: its own `case`, else its plan's `caseId`.
- A write that names an existing goal or intervention by `id` also needs the right to change that record's case.
- Answers: 401 without a session, 404 for a plan or goal that is not there, 403 when the caller may not change the
  case or the plan has no case to check (fail closed). Nothing is written on any of them.

## Scope

`lib/Controller/SociaalDomeinPlanController.php`. The reads (`plan`, `planOfCase`) are unchanged: they stay scoped by
OpenRegister RBAC through the store.
