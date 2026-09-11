# dmn-decision-tables Specification

## Purpose
TBD - created by archiving change dmn-decision-tables. Update Purpose after archive.

## Requirements

### Requirement: Decision tables are OpenRegister-defined data
The system MUST store decision tables (name, key, hitPolicy, inputs,
outputs, rules) as OpenRegister objects on the `decisionTable` schema, and
MUST expose admin-gated CRUD for them.

#### Scenario: Admin creates a decision table
- **GIVEN** an authenticated admin
- **WHEN** they POST a valid decision table (inputs, outputs, rules,
  hitPolicy) to `/api/decisions`
- **THEN** the system MUST persist it via OpenRegister and return the saved
  object with a generated id

#### Scenario: Non-admin cannot manage decision tables
- **GIVEN** an authenticated non-admin user
- **WHEN** they POST/PUT/DELETE against `/api/decisions`
- **THEN** the system MUST return 403 and MUST NOT persist any change

### Requirement: Expression grammar is a closed, safe subset, owned by OpenRegister
The system MUST evaluate rule input-entry expressions using only a fixed,
bounded grammar (wildcard, comparisons `< > <= >= = !=`, inclusive/exclusive
ranges, set membership `in (...)`, bare-literal equality) and MUST NOT execute
arbitrary code for any expression.

The grammar itself MUST live in OpenRegister's `UnaryTestEvaluator`, and dossiq
MUST NOT keep a second implementation of it. Two copies of a security-relevant
grammar are two things to keep in step, and the one that drifts is the one
nobody is reading.

The behaviour is unchanged: the grammar matrix that proves it moved to
openregister with the class, unaltered.

#### Scenario: Exclusive range boundary does not match
- **GIVEN** an input entry `(25000..40000]`
- **WHEN** the input value is exactly `25000`
- **THEN** the entry MUST NOT match

#### Scenario: Set membership matches one of the listed values
- **GIVEN** an input entry `in (gold, silver, bronze)` on a `string` input
- **WHEN** the input value is `silver`
- **THEN** the entry MUST match

#### Scenario: Range expression matches inclusively
- **GIVEN** an input entry `[0..25000]` on a `number`-typed input
- **WHEN** the input value is exactly `25000`
- **THEN** the entry MUST match

#### Scenario: Malformed expression is rejected, never treated as a match
- **GIVEN** an input entry `[1..` (unbalanced range)
- **WHEN** the decision is evaluated
- **THEN** the system MUST return an `invalid_expression` error

#### Scenario: dossiq ships no evaluator of its own
- **GIVEN** dossiq's source tree
- **THEN** it MUST contain no class implementing the unary-test grammar or the
  hit policies, and MUST resolve both from OpenRegister

### Requirement: Runtime input validation never silently defaults
The system MUST reject evaluation requests that supply an input key not
declared on the decision table, omit a declared input, or supply a value
that cannot be coerced to the input's declared type — each with a distinct,
stable `errorCode`, never a computed "best guess" output.

#### Scenario: Unknown input key
- **GIVEN** a decision table declaring inputs `income`, `householdSize`
- **WHEN** the caller POSTs `{"income": 10000, "houshold": 2}` (typo)
- **THEN** the system MUST return `unknown_input` and MUST NOT evaluate any
  rule

#### Scenario: Missing declared input
- **GIVEN** the same decision table
- **WHEN** the caller POSTs `{"income": 10000}` only
- **THEN** the system MUST return `missing_input`

#### Scenario: Type mismatch on a runtime input
- **GIVEN** the `income` input is declared `type: number`
- **WHEN** the caller POSTs `{"income": "not-a-number", "householdSize": 2}`
- **THEN** the system MUST return `type_mismatch` before evaluating any
  rule

### Requirement: UNIQUE hit policy guarantees exactly one answer
The system MUST return the single matching rule's outputs when exactly one
rule matches under UNIQUE, and MUST return a typed error — never a
silently-picked rule — when zero or more than one rule matches.

#### Scenario: UNIQUE with no match
- **GIVEN** a UNIQUE-hit-policy decision table
- **WHEN** no rule's input entries match the given inputs
- **THEN** the system MUST return `no_rule_matched`

#### Scenario: UNIQUE with more than one match
- **GIVEN** a UNIQUE-hit-policy decision table whose rules overlap
- **WHEN** two rules both match the given inputs
- **THEN** the system MUST return `hit_policy_violation` and MUST NOT
  return either rule's outputs as if it were authoritative

### Requirement: FIRST hit policy returns the first matching rule in order
The system MUST evaluate rules in declaration order under FIRST and return
the first match's outputs, ignoring any subsequent matches.

#### Scenario: FIRST returns the earliest matching rule
- **GIVEN** a FIRST-hit-policy decision table where rules 1 and 3 both match
  the given inputs
- **WHEN** the decision is evaluated
- **THEN** the system MUST return rule 1's outputs

### Requirement: COLLECT hit policy aggregates all matches, including none
The system MUST return an array per output containing every matching
rule's value under COLLECT, in declaration order, and MUST return empty
arrays (not an error) when zero rules match — COLLECT has no
singular-answer requirement.

#### Scenario: COLLECT aggregates multiple matches
- **GIVEN** a COLLECT-hit-policy decision table where rules 1 and 2 both
  match
- **WHEN** the decision is evaluated
- **THEN** each output MUST be an array containing both rules' values, in
  declaration order

#### Scenario: COLLECT with no match returns an empty aggregate
- **GIVEN** a COLLECT-hit-policy decision table where no rule matches
- **WHEN** the decision is evaluated
- **THEN** each output MUST be an empty array, and the response MUST NOT be
  an error

### Requirement: A workflow transition can invoke a named decision
The system MUST support an `evaluateDecision` automatic action on a
workflow transition that evaluates a decision table by `decisionKey`,
mapping case fields to decision inputs and writing decision outputs back
onto the case — reachable exactly like every other automatic-action type,
never an orphaned or unreachable capability.

#### Scenario: Transition evaluates a decision and writes the result onto the case
- **GIVEN** a workflow transition with automatic action
  `{type: evaluateDecision, decisionKey: subsidy-eligibility, inputMapping:
  {income: declaredIncome, householdSize: householdSize}, outputMapping:
  {eligible: subsidyEligible, tier: subsidyTier}}`
- **WHEN** the transition executes on a case with `declaredIncome: 20000,
  householdSize: 2`
- **THEN** the system MUST evaluate the `subsidy-eligibility` decision and
  MUST persist `subsidyEligible` and `subsidyTier` onto the case via
  OpenRegister

#### Scenario: Failed decision evaluation does not roll back the transition
- **GIVEN** the same transition, but the decision evaluation fails (e.g.
  `no_rule_matched`)
- **WHEN** the transition executes
- **THEN** the status change MUST still take effect (REQ-STE-5-002 —
  side-effect failures never roll back the status), and the failure MUST be
  recorded on `statusRecord.dispatchedActions[]`

### Requirement: Decisions are also invokable standalone via REST
The system MUST expose `POST /api/decisions/{id}/evaluate` for any
authenticated user, independent of any workflow transition, so a decision
table can be tested or consumed outside a case's lifecycle.

#### Scenario: Standalone evaluation
- **GIVEN** an authenticated user and a published decision table id
- **WHEN** they POST valid inputs to `/api/decisions/{id}/evaluate`
- **THEN** the system MUST return the computed outputs without requiring
  any case or workflow transition

### Requirement: PRIORITY and ANY hit policies are evaluated by OpenRegister
The system MUST evaluate a decision table declaring `PRIORITY` or `ANY` rather
than refusing it. `PRIORITY` MUST return the matching rule with the highest
priority, breaking ties by declaration order. `ANY` MUST return the shared
output of the matching rules and MUST raise `hit_policy_violation` when they
disagree, because a table declaring `ANY` asserts that its overlapping rules
agree.

This replaces the previous requirement that both were rejected with
`hit_policy_not_implemented`. That requirement described a limitation of
dossiq's own engine, not a decision about DMN. The schema has offered all five
policies in its enum throughout, so the refusal was visible to users as a form
that offered a choice the engine would not honour.

#### Scenario: PRIORITY returns the highest-priority match
- **GIVEN** a decision table with `hitPolicy: PRIORITY` and matching rules of priority 1, 10 and 5
- **WHEN** it is evaluated
- **THEN** the rule with priority 10 MUST win

#### Scenario: ANY refuses rules that disagree
- **GIVEN** a table with `hitPolicy: ANY` and two matching rules with different outputs
- **WHEN** it is evaluated
- **THEN** the system MUST raise `hit_policy_violation` and MUST NOT pick one

#### Scenario: A PRIORITY table is no longer refused for its policy
- **GIVEN** any decision table declaring `hitPolicy: PRIORITY`
- **WHEN** it is evaluated
- **THEN** the system MUST NOT return `hit_policy_not_implemented`, and MUST
  fail only on the table's own contents if those are invalid
