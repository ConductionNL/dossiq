# Enforcement intervention from the case

## Why

Row 7.10 (pick an enforcement intervention from the national enforcement strategy matrix and record overrides). The configuration is shipped: packages `lhs-matrix` and `lhs-intervention` (decision 204) seed the grid cells and the stored decision table, and `LhsController` answers `POST /api/lhs/recommend` and `POST /api/lhs/override`. The changes `lhs-packages` and `lhs-advice-notice` are fully ticked. But nothing a handler opens calls those two routes: on development `a5c9b4153` no file under `src/` names them, and the `LhsRecommendations` pages sit under settings, read-only, with no menu entry. Board DqHandhaving draws the step on the case.

## What changes

- A generic "advice from a decision table" step on the case: a header action and dialog that asks the case type's configured decision table for a recommendation from the inputs the table declares, shows the outcome, and records either acceptance or an override with a reason. Nothing in it is named after LHS (decision 182); the LHS is the configuration the enforcement case types point at.
- A case type opts in by naming the decision table (`caseType.adviceDecisionTable`), so any procedure with a stored decision table gets the same step.
- The recorded recommendation is its own object linked from the case (decision 197), listed on the case's decisions widget.

## Impact

- `src/manifest.json` CaseDetail: header action `advice-from-decision-table`, visible when `@context.caseType.adviceDecisionTable`; new dialog `src/dialogs/DecisionTableAdviceDialog.vue`.
- `LhsController` routes stay as the backend; a generic alias `/api/decision-advice/recommend` and `/override` is added and the LHS paths forward to it for one release (rename per decision 184).
- Packages: `lhs-matrix` sets `adviceDecisionTable` on the handhaving case types in `lib/Settings/packages/vth/case-type.json`.
