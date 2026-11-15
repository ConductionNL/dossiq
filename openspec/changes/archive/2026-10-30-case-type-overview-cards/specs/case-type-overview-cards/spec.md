# case-type-overview-cards Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-type-overview-cards](../../)

## Purpose

The case type Overview and phase board carry the cards and links the boards draw.

## ADDED Requirements

### Requirement: The case type Overview shows the board's cards

The Overview tab SHALL show Statuses, Rules, Rights, Personal data and Translations in that order, Personal data and
Translations side by side. The Rules card SHALL list the case schema's rules from OpenRegister with an On or Off pill.

#### Scenario: Overview

- **GIVEN** a case type page on Overview
- **THEN** it shows Statussen, Regels, Rechten, Privacy and Vertalingen, as on board DqZaaktypeOverzicht

### Requirement: The phase board opens the case type's workflow

The phase board SHALL carry a header link "View as flow" to `WorkflowDefinitionDetail` with the case type's
`workflowDefinition` as its id.

#### Scenario: Phases and tasks

- **GIVEN** a case type with a workflow, on Phases and tasks
- **THEN** "Bekijken als flow" opens that workflow
