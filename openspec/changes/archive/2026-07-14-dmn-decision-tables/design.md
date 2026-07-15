# Design: dmn-decision-tables

## Context

Procest already separates **definition** (OpenRegister objects:
`workflowTemplate`, `caseType`, `routingRule`, …) from **evaluation** (pure
services: `GuardRegistry`, `RoutingEngine`). DMN decision tables follow the
identical split:

- `decisionTable` — an OR object (definition, data, admin-editable).
- `DecisionEngine` — a pure PHP evaluator (no OR/HTTP/DB dependency,
  exhaustively unit-testable, deterministic).

This mirrors `RoutingRuleService` (storage + validation) delegating to the
pure `RoutingEngine` (evaluation) — the closest existing precedent in this
codebase.

## Decision 1 — Model shape (JSON, DMN-XML import as follow-up)

```json
{
  "name": "Subsidy eligibility",
  "key": "subsidy-eligibility",
  "description": "Determines subsidy eligibility and tier from income and household size",
  "hitPolicy": "UNIQUE",
  "inputs": [
    { "name": "income", "label": "Household income (EUR/year)", "type": "number" },
    { "name": "householdSize", "label": "Household size", "type": "number" }
  ],
  "outputs": [
    { "name": "eligible", "label": "Eligible", "type": "boolean" },
    { "name": "tier", "label": "Subsidy tier", "type": "string" }
  ],
  "rules": [
    {
      "id": "r1",
      "annotation": "Low income, any household",
      "inputEntries": ["[0..25000]", "-"],
      "outputEntries": [true, "gold"]
    },
    {
      "id": "r2",
      "annotation": "Mid income, large household",
      "inputEntries": ["(25000..40000]", ">=4"],
      "outputEntries": [true, "silver"]
    },
    {
      "id": "r3",
      "annotation": "High income",
      "inputEntries": ["> 40000", "-"],
      "outputEntries": [false, "none"]
    }
  ],
  "enabled": true
}
```

Note `r1`/`r2`/`r3` are mutually exclusive on `income` — a well-formed
UNIQUE table must not have overlapping conditions (Decision 3 explains why
UNIQUE treats an overlap as `hit_policy_violation` rather than picking a
winner). Mid-income households smaller than 4 fall into no rule and
`no_rule_matched` under UNIQUE, by design — the table above is deliberately
partial to also illustrate that gap.

`rules[].inputEntries`/`outputEntries` are **positional**, aligned by index
to `inputs`/`outputs` — the same "hit policy table" shape DMN itself uses
(rows = rules, columns = inputs then outputs). This is a deliberate,
minimal JSON encoding of the DMN decision-table concept: `hitPolicy`,
`input` (with a `type` in place of a full FEEL `inputExpression`), `output`,
and `rule` (with `inputEntry`/`outputEntry` cells) map 1:1 onto standard DMN
XML elements (`<decision><decisionTable hitPolicy="…">` /
`<input><inputExpression>` / `<output>` / `<rule><inputEntry><text>`). A
follow-up change can add a DMN-XML importer/exporter that walks this exact
structure — **not built here** because the XML schema (namespaces,
`FEEL` expression trees, `dmndi` diagram interchange) is materially larger
scope than the evaluation engine itself, and every fleet consumer of this
capability (the workflow hook, the REST API, the settings UI) only ever
needs the JSON shape.

## Decision 2 — Expression grammar (bounded, safe subset of FEEL)

Each `inputEntries[i]` cell is a **string expression** evaluated against
the coerced runtime value of `inputs[i]`. The grammar is intentionally
tiny and closed — every branch is a fixed parse + comparison, there is
**no `eval()`, no reflection, no user-supplied code path**:

| Form | Example | Meaning |
|---|---|---|
| empty / `-` | `-` | wildcard — always matches (DMN convention) |
| comparison | `< 10`, `>= 3`, `= "gold"`, `!= "bronze"` | operator + literal, coerced to the input's declared `type` |
| inclusive range | `[1..10]` | `1 <= value <= 10` |
| exclusive range | `(1..10)` | `1 < value < 10` |
| mixed range | `(1..10]`, `[1..10)` | half-open, either side |
| set membership | `in (a,b,c)`, `in ("a b", "c,d")` | value equals one of the (optionally quoted) members |
| bare literal | `gold`, `42`, `true` | equality against the coerced literal |

Parsing order (`ExpressionEvaluator::matches()`): trim → wildcard check →
`in (...)` prefix → range (`[`/`(` … `..` … `]`/`)`) → 2-char operator
(`<=`, `>=`, `!=`) → 1-char operator (`<`, `>`, `=`) → bare-literal
equality. A malformed expression (unbalanced brackets, non-numeric range
bound on a `number` input, an operator with no operand) throws
`DecisionEvaluationException('invalid_expression', …)` — it is a decision-
table authoring bug, surfaced immediately, never silently treated as "no
match" or "always match".

**Type coercion** (`ExpressionEvaluator::coerce()`) is driven by the
input's declared `type`:

- `string` — cast to string.
- `number` — `is_numeric()` required; otherwise `type_mismatch`.
- `boolean` — accepts real booleans or the literal strings `true`/`false`;
  otherwise `type_mismatch`.
- `date` — parsed via `DateTimeImmutable`; comparisons operate on the Unix
  timestamp; unparsable input throws `type_mismatch`.

Runtime **input** values (the caller's payload, not the rule text) are
coerced once per `evaluate()` call — a non-numeric value supplied for a
`number` input fails the whole evaluation with `type_mismatch` before any
rule is checked, rather than silently failing every rule and looking like
"no match".

## Decision 3 — Hit policies

Implemented in full: **UNIQUE**, **FIRST**, **COLLECT**.
Documented but rejected with a typed error: **PRIORITY**, **ANY** (follow-up).

| Policy | 0 rules match | 1 rule matches | >1 rules match |
|---|---|---|---|
| UNIQUE | `no_rule_matched` | that rule's outputs | `hit_policy_violation` (ambiguous — UNIQUE guarantees at most one match by table construction; more than one is a decision-table authoring bug, not a runtime default) |
| FIRST | `no_rule_matched` | that rule's outputs | first matching rule's outputs, in declaration order |
| COLLECT | `{}` empty arrays per output (a valid, legitimate "nothing applies" result — DMN COLLECT has no singular-answer requirement) | outputs as single-element arrays | outputs as arrays, one entry per matching rule, in declaration order |
| PRIORITY | — | — | `hit_policy_not_implemented` (requires a per-output priority ordering not modelled here; follow-up) |
| ANY | — | — | `hit_policy_not_implemented` (requires validating all matches agree; follow-up) |

The asymmetry between UNIQUE/FIRST (0 matches is *always* an error) and
COLLECT (0 matches is a legitimate empty aggregation) is intentional and
matches the DMN spec's own semantics — UNIQUE/FIRST promise a singular
decision, COLLECT promises an aggregate that may be empty. This is the one
place the engine does NOT treat "no match" as a hard error, and it is
called out explicitly here (and in the spec) so it is never mistaken for a
silent default.

## Decision 4 — Errors are typed, never silent

`DecisionEvaluationException` carries a stable `errorCode` (never
message-text matching):

- `unknown_input` — the caller supplied an input key not declared on the
  decision table (typo guard).
- `missing_input` — a declared input has no value in the caller's payload.
- `type_mismatch` — a runtime value or a rule literal cannot be coerced to
  the declared type.
- `invalid_expression` — a rule's `inputEntries` cell does not parse under
  the grammar above.
- `no_rule_matched` — UNIQUE/FIRST found zero matching rules.
- `hit_policy_violation` — UNIQUE found more than one matching rule.
- `hit_policy_not_implemented` — PRIORITY/ANY requested.

`DecisionTableController::evaluate()` maps these to HTTP status:
`unknown_input`/`missing_input`/`type_mismatch`/`invalid_expression`/
`hit_policy_not_implemented` → 400; `no_rule_matched`/`hit_policy_violation`
→ 422.

## Decision 5 — Workflow integration (not an orphaned capability)

`EvaluateDecisionHandler implements ActionHandlerInterface` — the exact
extension point `SetFieldHandler`/`NotifyHandler`/etc. already use, wired
into `ActionHandlerRegistry`'s constructor alongside the 8 existing
handlers. A transition's `automaticActions[]` entry:

```json
{
  "type": "evaluateDecision",
  "decisionKey": "subsidy-eligibility",
  "inputMapping": { "income": "declaredIncome", "householdSize": "householdSize" },
  "outputMapping": { "eligible": "subsidyEligible", "tier": "subsidyTier" }
}
```

`inputMapping`/`outputMapping` are optional; when absent, the handler
assumes the decision's input/output names match the case field names
directly (same-name default, like `SetFieldHandler`'s `field` config).
The handler:

1. Looks up the `decisionTable` by `decisionKey` via
   `DecisionTableService::findByKey()`.
2. Builds `inputs[decisionInputName] = case[caseFieldName]` for each
   declared input.
3. Calls `DecisionEngine::evaluate()`.
4. Writes `case[caseFieldName] = outputs[decisionOutputName]` for each
   declared output via `ObjectService::saveObject()` — identical write
   path to `SetFieldHandler`.
5. Per `ActionHandlerInterface`'s contract, catches every `\Throwable`
   internally and returns `ActionResult::failure(error: <errorCode>)` —
   a failed decision evaluation logs and is recorded on the
   `statusRecord.dispatchedActions[]` trail exactly like any other failed
   action, and per REQ-STE-5-002 does NOT roll back the status change.

This is invokable two ways — from a workflow transition (above) and
standalone via `POST /api/decisions/{id}/evaluate` — proving the capability
is reachable, not orphaned (fleet defect class: implemented-but-uncalled).
An end-to-end PHPUnit test (`EvaluateDecisionHandlerTest`) exercises the
full path: transition config → handler → engine → case write.

## Decision 6 — UI scope

A full spreadsheet-style rule grid (columns per input/output, inline cell
editors, drag-to-reorder rules) is a substantial standalone frontend effort
comparable to `WorkflowEditor.vue`. Shipping here: a list view (name, key,
hit policy, rule count, enabled toggle) plus a structured form for the
top-level fields (name/key/description/hitPolicy) and a JSON textarea for
`inputs`/`outputs`/`rules`, with client-side structural validation
(mirrors the backend's shape checks) before submit — so a coordinator can
configure a full decision table without writing PHP, even though the
editing experience for the rule grid itself is JSON rather than a grid UI.
The full grid editor is documented here as a follow-up.

## Follow-ups (explicitly out of scope for this change)

1. DMN-XML import/export (Decision 1).
2. PRIORITY and ANY hit policies (Decision 3).
3. Spreadsheet-style rule-grid editor (Decision 6).
