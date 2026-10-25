# lhs-advice Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [lhs-advice-notice](../../)

## Purpose

The LHS advice says its outcome in words under the matrix.

## ADDED Requirements

### Requirement: The LHS matrix states the advice

The LHS advice page SHALL show, under the matrix, an info notice naming the recommended intervention in bold and the
matrix version, behaviour row and consequence column it came from.

#### Scenario: DqHandhaving

- **WHEN** a handler opens an advice of last onder dwangsom at gedrag 2 and gevolg C from matrix version 2
- **THEN** the notice reads "Advies: Last onder dwangsom" in bold and "Uitkomst van de beslistabel, versie 2, met gedrag
  2 en gevolg C."
