# case-types Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-type-rules-as-rows](../../)

## Purpose

A reader understands what is checked when a case is saved.

## ADDED Requirements

### Requirement: The Rules tab lists rules as rows

The Rules tab SHALL draw "Rules on the case" as a titled card with one row per rule, titled by the field the rule writes
or by its kind, with one line saying what it does and a pill (Calculated, On, Off), and SHALL keep trying a rule
against a case behind a disclosure.

#### Scenario: DqZaaktypeRegels

- **GIVEN** the Rules tab of a case type
- **THEN** the deadline calculation reads "Uiterste datum" with its first descriptive sentence and "Berekend", and no
  table wider than the card is drawn
