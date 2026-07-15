# Proposal: dmn-decision-tables

## Why

DMN (Decision Model and Notation) decision tables let domain experts
configure complex permit/eligibility rules without a developer — a
researched requirement (procest's own README lists "DMN: roadmap — no DMN
engine ships today"). It pairs naturally with procest's existing BPMN-style
workflow engine (`WorkflowEngineService`, `StatusTransitionService`,
`lib/Service/Transitions/*`): a workflow step can already run guards and
automatic actions, but there is no way to let a coordinator configure "if
income < 30000 and household = single then eligible = true" without
shipping PHP.

Decision DEFINITIONS are data — they belong in OpenRegister like every other
procest configuration object (workflowTemplate, routingRule, caseType).
EVALUATION is a pure, deterministic, exhaustively-testable function of
(decision definition, inputs) → outputs; it must never touch storage,
must never silently default, and must be safe to run on untrusted rule
configuration (no `eval()`, no arbitrary PHP).

## What Changes

- Add OR schema `decisionTable` (register.d fragment
  `95-dmn-decision-tables.json`, ADR-037 pattern): `name`, `key` (machine
  identifier used to invoke the decision by name), `description`,
  `hitPolicy` (UNIQUE/FIRST/PRIORITY/ANY/COLLECT), `inputs[]`
  (name/label/type), `outputs[]` (name/label/type), `rules[]`
  (inputEntries[]/outputEntries[]/annotation, positionally aligned to
  `inputs`/`outputs`), `enabled`.
- Add `lib/Service/Dmn/ExpressionEvaluator.php`: a pure, bounded FEEL-like
  grammar evaluator — comparisons (`< > <= >= = !=`), inclusive/exclusive
  ranges (`[1..10]`, `(1..10]`, …), sets (`in (a,b,c)`), booleans, bare
  literals, and the wildcard `-`/empty. No `eval()`, no reflection, no
  dynamic code execution — every branch is a fixed parse + comparison.
  Grammar documented in `design.md`.
- Add `lib/Service/Dmn/DecisionEngine.php`: pure evaluation given a decision
  table definition + an inputs map. Implements hit policies UNIQUE, FIRST,
  COLLECT fully; PRIORITY and ANY are explicitly rejected with a clear
  `hit_policy_not_implemented` error (never silently mis-evaluated) —
  documented as follow-up. Errors (`unknown_input`, `missing_input`,
  `type_mismatch`, `no_rule_matched`, `hit_policy_violation`) are typed
  exceptions with a stable `errorCode`, never a silent default output.
- Add `lib/Service/Dmn/DecisionTableService.php`: CRUD + structural
  validation over OpenRegister, following the `RoutingRuleService` pattern
  (`SettingsService::getObjectService()`, `register`/`decision_table_schema`
  app-config keys).
- Add `lib/Controller/DecisionTableController.php`
  (`index`/`create`/`update`/`destroy` admin-gated exactly like
  `KccRoutingController`; `evaluate` open to any authenticated user) +
  routes `GET/POST /api/decisions`, `PUT/DELETE /api/decisions/{id}`,
  `POST /api/decisions/{id}/evaluate`.
- Workflow integration (closes the orphaned-capability risk): add
  `lib/Service/Transitions/EvaluateDecisionHandler.php` implementing the
  existing `ActionHandlerInterface` — an automatic action
  `{type: 'evaluateDecision', decisionKey, inputMapping?, outputMapping?}`
  evaluates the named decision against the case (field-mapped inputs →
  field-mapped outputs written back via `ObjectService::saveObject`) exactly
  like `SetFieldHandler` does. Registered in `ActionHandlerRegistry`
  alongside the 8 existing handlers — reachable from any workflow
  transition's `automaticActions[]`, no parallel workflow system.
- UI: `src/views/settings/tabs/DecisionTablesTab.vue` — list + JSON editor
  (name/key/hitPolicy fields plus a textarea for inputs/outputs/rules JSON,
  validated client-side before submit) added as a new Settings tab,
  following the existing `WorkflowTab.vue`/settings-tab pattern. A full
  spreadsheet-style rule grid is documented as a follow-up (design.md).
- l10n: EN+NL pairs for all new strings.
- Tests: PHPUnit grammar matrix, hit-policy behaviour, CRUD auth, the
  evaluate endpoint, and an end-to-end workflow-hook test proving a
  transition's `automaticActions[]` entry actually invokes the decision and
  writes the result onto the case. Vitest for the pure JS validation
  helpers used by the settings tab.

## Impact

- Affected specs: new `dmn-decision-tables` capability.
- Affected code: `lib/Settings/register.d/95-dmn-decision-tables.json`,
  `lib/Service/SettingsService.php` (new config key + slug mapping),
  `lib/Service/Dmn/*`, `lib/Service/Transitions/EvaluateDecisionHandler.php`,
  `lib/Service/Transitions/ActionHandlerRegistry.php`,
  `lib/Controller/DecisionTableController.php`, `appinfo/routes.php`,
  `src/views/settings/tabs/DecisionTablesTab.vue`, `src/services/`,
  `l10n/{en,nl}.{js,json}`, tests.
- NO new composer/npm dependencies. NO parallel workflow engine — the
  decision engine is invoked exclusively through the existing
  `ActionHandlerInterface` extension point and a standalone REST endpoint.
- Follow-up (documented, not built here): DMN-XML import/export, PRIORITY
  and ANY hit policies, a full spreadsheet rule-grid editor.
