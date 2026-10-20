# case-type-phase-add-task Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-type-phase-add-task](../../)

## Purpose

A task is added to a phase of a case type from its phase board.

## ADDED Requirements

### Requirement: A task is added to a phase of the draft workflow

`POST /api/case-types/{id}/phases/{phase}/steps` SHALL be admin-only and SHALL add a step with the given title under the
phase, ordered after that phase's last step, to the case type's newest draft workflow definition, and answer 201 with
the step. It SHALL answer 400 without a title, 404 when the phase is not a status of the case type, and 409 when the
case type has no draft definition, and SHALL NOT change a published definition. The phase board SHALL offer this in every
phase.

#### Scenario: Add a task to Beoordeling

- **GIVEN** a case type with a draft version 5 and a phase "Beoordeling" holding one task at order 3
- **WHEN** the administrator adds "Welstandsadvies vragen" to Beoordeling
- **THEN** version 5's steps hold a task "Welstandsadvies vragen" in Beoordeling at order 4

#### Scenario: No draft

- **GIVEN** a case type whose only workflow version is published
- **WHEN** a task is added
- **THEN** the answer is 409 and the published version is unchanged
