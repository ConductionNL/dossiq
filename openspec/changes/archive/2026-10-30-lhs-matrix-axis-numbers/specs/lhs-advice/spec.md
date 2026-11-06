# lhs-advice Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [lhs-matrix-axis-numbers](../../)

## Purpose

The LHS matrix names its rows and columns the way the advice refers to them.

## ADDED Requirements

### Requirement: The LHS matrix numbers its axes

The LHS advice matrix SHALL label behaviour rows with their number (1 to 4) and consequence columns with their letter
(A onward), followed by the word.

#### Scenario: DqHandhaving

- **WHEN** a handler opens an LHS advice
- **THEN** the rows read "Gedrag 1: welwillend" to "Gedrag 4: crimineel" and the columns "Gevolg A: gering",
  "Gevolg B: aanzienlijk", "Gevolg C: ernstig"
