# Tasks: dmn-decision-tables

## 1. Model (OR schema)

- [x] 1.1 Add `lib/Settings/register.d/95-dmn-decision-tables.json` (ADR-037
      fragment): `decisionTable` schema — `name`, `key` (required, machine
      identifier), `description`, `hitPolicy` (enum
      UNIQUE/FIRST/PRIORITY/ANY/COLLECT, default UNIQUE), `inputs[]`
      (name/label/type), `outputs[]` (name/label/type), `rules[]`
      (id/annotation/inputEntries[]/outputEntries[]), `enabled`. Register it
      under `registers.procest.schemas`.
- [x] 1.2 Add `decision_table_schema` to `SettingsService::CONFIG_KEYS` and
      `decisionTable => decision_table_schema` to `SLUG_TO_CONFIG_KEY`.

## 2. Expression evaluator (pure, safe)

- [x] 2.1 Create `lib/Service/Dmn/DecisionEvaluationException.php` — extends
      `\RuntimeException`, carries a stable `errorCode` + details array.
- [x] 2.2 Create `lib/Service/Dmn/ExpressionEvaluator.php`: `matches(string
      $expression, mixed $value, string $type): bool` implementing the
      grammar in design.md Decision 2 (wildcard, comparisons, ranges, sets,
      bare literal) + `coerce(mixed $value, string $type): mixed` (type
      coercion per Decision 2). No `eval()`/reflection/dynamic code.

## 3. Decision engine (pure, hit policies)

- [x] 3.1 Create `lib/Service/Dmn/DecisionEngine.php`: `evaluate(array
      $decisionTable, array $inputs): array` — validates inputs
      (`unknown_input`/`missing_input`), evaluates every rule's
      `inputEntries` against the coerced inputs, applies the hit policy
      (UNIQUE/FIRST/COLLECT full; PRIORITY/ANY → `hit_policy_not_implemented`)
      per design.md Decision 3, returns `{outputs, matchedRuleIds,
      hitPolicy}`.

## 4. Storage service + controller

- [x] 4.1 Create `lib/Service/Dmn/DecisionTableService.php`: `listTables()`,
      `createTable()`, `updateTable()`, `deleteTable()`, `getTable()`,
      `findByKey()` — structural validation (hitPolicy enum, inputs/outputs
      shape, rule entry-count alignment) + OR persistence, following
      `RoutingRuleService`'s `resolve()`/`toArray()` pattern.
- [x] 4.2 Create `lib/Controller/DecisionTableController.php`:
      `index`/`create`/`update`/`destroy` admin-gated exactly like
      `KccRoutingController` (`AuthorizedAdminSetting` + `requireAdmin()`
      body check, no `@NoAdminRequired`); `evaluate(string $id)` open to any
      authenticated user, maps `DecisionEvaluationException::errorCode` to
      HTTP status per design.md Decision 4.
- [x] 4.3 Register routes in `appinfo/routes.php`:
      `GET/POST /api/decisions`, `PUT/DELETE /api/decisions/{id}`,
      `POST /api/decisions/{id}/evaluate`.

## 5. Workflow integration (not orphaned)

- [x] 5.1 Create `lib/Service/Transitions/EvaluateDecisionHandler.php`
      implementing `ActionHandlerInterface` per design.md Decision 5 —
      looks up the decision by `decisionKey`, maps case fields to decision
      inputs, evaluates, writes outputs back onto the case via
      `ObjectService::saveObject`, catches every `\Throwable` and returns
      `ActionResult::failure` (never propagates, per the interface
      contract).
- [x] 5.2 Register `'evaluateDecision' => $evaluateDecision` in
      `ActionHandlerRegistry`'s constructor (9th handler) so it is reachable
      from any workflow transition's `automaticActions[]`.

## 6. UI

- [x] 6.1 Create `src/views/settings/tabs/DecisionTablesTab.vue`: list
      (name/key/hitPolicy/rule count/enabled) + create/edit form
      (name/key/description/hitPolicy select + JSON textarea for
      inputs/outputs/rules with client-side structural validation before
      submit), NC CSS vars only, following `WorkflowTab.vue`'s tab pattern.
- [x] 6.2 Create `src/services/decisionTableApi.js` (axios CRUD + evaluate
      wrapper) + `src/utils/decisionTableHelpers.js` (pure: JSON-shape
      validator reused by the tab, rule-count summary).
- [x] 6.3 Wire the new tab into the settings tab registry so it is actually
      reachable from the Settings page (not orphaned).
- [x] 6.4 l10n: EN+NL pairs for all new strings (ENGLISH keys),
      `node tests/l10n/check-l10n.js` green.

## 7. Tests

- [x] 7.1 `tests/Unit/Service/Dmn/ExpressionEvaluatorTest.php`: every
      operator (`< > <= >= = !=`), inclusive/exclusive/mixed ranges, sets
      (quoted + unquoted), wildcard (`-`/empty), booleans, all four types,
      malformed expressions (unbalanced bracket, non-numeric range bound,
      operator with no operand) throw `invalid_expression`.
- [x] 7.2 `tests/Unit/Service/Dmn/DecisionEngineTest.php`: UNIQUE
      (no-match error, single-match success, ambiguous-match error), FIRST
      (declaration-order precedence), COLLECT (zero-match empty arrays,
      multi-match aggregation), PRIORITY/ANY (`hit_policy_not_implemented`),
      `unknown_input`, `missing_input`, `type_mismatch`.
- [x] 7.3 `tests/Unit/Controller/DecisionTableControllerTest.php`: CRUD
      401/403/200/201 auth matrix, `evaluate` success + each error-code →
      HTTP-status mapping.
- [x] 7.4 `tests/Unit/Service/Transitions/EvaluateDecisionHandlerTest.php`:
      end-to-end — transition action config → handler → engine → case
      field written, proving the workflow hook is real and reachable.
- [x] 7.5 `tests/vitest/decisionTableHelpers.spec.js`: JSON-shape validator
      (valid/invalid inputs/outputs/rules), rule-count summary.
- [x] 7.6 Full PHPUnit suite via the CI `php:8.3-cli` container recipe,
      diffed against a pristine `origin/development` baseline (same filter
      both sides — only NEW failures block); `npm run test:vitest`;
      `npm run build`; `node tests/l10n/check-l10n.js`.

## Acceptance criteria

- A coordinator can define a decision table (inputs/outputs/rules/hit
  policy) through the settings UI without writing PHP.
- `POST /api/decisions/{id}/evaluate` returns deterministic outputs for
  UNIQUE/FIRST/COLLECT, and a typed error (never a silent default) for
  no-match, ambiguous-match, unknown/missing input, or type mismatch.
- A workflow transition's `automaticActions[]` can reference a decision by
  `decisionKey` and have its outputs written onto the case — proven by an
  end-to-end test, not just unit coverage of the handler in isolation.
- The expression grammar never executes arbitrary code — it is a fixed
  parse + comparison over a closed set of forms.
