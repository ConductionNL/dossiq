# dmn-decision-tables Specification

## Purpose

dossiq stops shipping its own DMN engine and consumes OpenRegister's. The two
were the same class apart from docblocks, so the evaluation behaviour dossiq's
tables already relied on is unchanged. What changes is that `PRIORITY` and
`ANY`, which dossiq's schema has offered in its enum all along, now actually
evaluate.

Renamed from "PRIORITY and ANY hit policies are explicitly unsupported" and
"Expression grammar is a closed, safe subset" respectively.

## MODIFIED Requirements

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
