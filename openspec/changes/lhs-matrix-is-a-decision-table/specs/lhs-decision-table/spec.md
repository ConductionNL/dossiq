# lhs-decision-table Specification

## Purpose

The enforcement matrix becomes a decision table, evaluated by the one evaluator
the fleet shares, instead of a hand-indexed dictionary in dossiq.

## ADDED Requirements

### Requirement: REQ-LDT-001 A matrix projects onto a decision table

The system SHALL project each stored LHS matrix onto a decision table whose
inputs are severity, behaviour and actorType, whose output is the intervention,
and whose rules are the matrix cells.

Each rule SHALL be identified by its own triple, so a rule is traceable back to
the cell it came from without depending on order.

#### Scenario: Each cell becomes a rule

- **GIVEN** a matrix of four cells over two severities, two behaviours and one actor type
- **WHEN** it is projected
- **THEN** the table MUST declare those three inputs and one output, and carry
  four rules, each identified by its own `severity:behaviour:actorType`

#### Scenario: Both stored shapes are read

- **GIVEN** a matrix whose axes and cells are stored as JSON strings
- **THEN** it MUST project identically to one storing them as arrays

### Requirement: REQ-LDT-002 The table declares UNIQUE

The projected table SHALL declare the `UNIQUE` hit policy.

A grid has exactly one cell per triple. UNIQUE turns an overlapping pair into a
refusal, where the hand-indexed dictionary silently keeps whichever cell was
read last.

#### Scenario: The projection declares UNIQUE

- **GIVEN** any projected matrix
- **THEN** its `hitPolicy` MUST be `UNIQUE`

### Requirement: REQ-LDT-003 An inconsistent matrix is refused, not projected

The system SHALL skip a matrix in which any cell names a value that is not on
the corresponding axis, and SHALL report the reason.

Projecting it would carry the defect across while looking like a migration that
worked: the rule would be unreachable in the table exactly as the cell is
unreachable in the matrix. dossiq#1596 is that defect, shipped.

#### Scenario: A cell off its axis skips the matrix

- **GIVEN** a matrix whose cell names an actor type absent from its actorTypeAxis
- **WHEN** the migration runs
- **THEN** no table MUST be written for it, and the summary MUST count it
  skipped with the reason

### Requirement: REQ-LDT-004 The projection arrives disabled

The projected table SHALL be created disabled.

The matrix still drives recommendations. A table that also answered would be a
second source of truth for an enforcement decision.

#### Scenario: A freshly projected matrix is disabled

- **GIVEN** any projected matrix
- **THEN** the table MUST carry `enabled: false`
