# Proposal: family-plan-by-case

## Why

Board DqSociaalDomein reads "Gezinsplan: In opbouw" in De melding and draws the Gezinsplan card from the case's
plan. The page found the plan through `@object.gezinsplanId`, but the case schema has no such field (only the old
jeugdwetZaak schema did). So no case ever found its plan. The relation the gezinsplan schema really holds is
`caseId`: the plan points at its case.

## What changes

- New `GET /api/cases/{caseId}/family-plan` (`SociaalDomeinPlanController::planOfCase`):
  - it answers the plan whose `caseId` is the case (the most recently prepared one when there are several) in the
    shape of `/api/family-plans/{planId}`;
  - a case without a plan answers an empty plan;
  - an unreadable register stays a refusal, so "no plan" and "cannot read" never look alike;
  - the read is scoped by OpenRegister RBAC as before.
- The Gezinsplan card (`goal-plan`: review banner and goal cards) reads that endpoint.
- De melding (`case-summary`) reads the plan as its facts `source` (gezinsplan filtered on `caseId = @objectId`,
  newest first) and shows "Family plan: {status}" through the status labels of gezinsplan 1.3.0. Its other rows read
  the case (`from: "object"`), so a case without a plan, or a reader who may not see plans, still sees them.

## Scope

Generic: any case whose plan points at it. Stacked on r26/family-plan-status 95fe9c05a (gezinsplan.status) and on
r25/report-card-handler-name 3c00c0f27 (the same card's Handler row).
