# lhs-advice Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [lhs-matrix-label-width-notice-words](../../)

## Purpose

The LHS matrix is laid out and worded as the board.

## ADDED Requirements

### Requirement: The LHS matrix's label column and notice words follow the board

The LHS advice matrix SHALL give its row labels a 170px column, and its notice SHALL name the intervention in lower case
mid-sentence.

#### Scenario: DqHandhaving

- **WHEN** a handler opens an advice of last onder dwangsom
- **THEN** the notice reads "Advies: last onder dwangsom" and the cells start after a 170px label column
