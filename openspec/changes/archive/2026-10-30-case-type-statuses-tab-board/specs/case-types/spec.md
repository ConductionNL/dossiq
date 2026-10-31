# case-types Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-type-statuses-tab-board](../../)

## Purpose

The Statuses tab reads as board DqZaaktypeStatussen.

## ADDED Requirements

### Requirement: The Statuses tab reads as the board

The Statuses tab SHALL subtitle Statuses and Results with what each list is for, SHALL label each Add with what it adds,
SHALL list a status by name, list colour, whether it is final and its public label, and SHALL show a result's retention
period in words.

#### Scenario: DqZaaktypeStatussen

- **GIVEN** the Statuses tab of a case type
- **THEN** Statuses reads "Volgorde, kleur en wat de aanvrager ziet" under its title with "Status toevoegen", and a
  result kept ten years reads "10 jaar"
