# case-type-add-rule Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-type-add-rule](../../)

## Purpose

A rule can be added from the case type's Rules card.

## ADDED Requirements

### Requirement: The Rules card adds a rule as a new flow

The case type Overview's Rules card SHALL carry a header link "Add rule" that opens `FlowDetail` with id `new`.

#### Scenario: Add rule

- **GIVEN** the case type Overview
- **WHEN** an administrator picks "Regel toevoegen"
- **THEN** the flow editor opens on a new flow
