# case-type-choice-lists Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-type-choice-lists](../../)

## Purpose

A case type's choice lists are edited beside its fields.

## ADDED Requirements

### Requirement: A field's choices are edited beside Fields

Beside the Fields tab the side column SHALL list the case type's fields whose `enumValues` is set. Editing one SHALL open
a form with only its choices, one per line, and save them to the field's `enumValues`. The card SHALL NOT add fields.

#### Scenario: Soort bouwwerk

- **GIVEN** a case type with a field "Soort bouwwerk" whose choices are Woning, Bijgebouw and Dakkapel
- **WHEN** the administrator opens it from Keuzelijsten and adds "Erfscheiding" on a new line
- **THEN** the field's choices are Woning, Bijgebouw, Dakkapel and Erfscheiding
