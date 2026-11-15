# case-dialogs Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-dialogs-board-titles](../../)

## Purpose

A case dialog says what it does.

## ADDED Requirements

### Requirement: A case dialog is titled by what it does

A case dialog that opens a form SHALL be titled by its act, SHALL confirm with that act's verb, and SHALL ask its fields
in the order the board draws them, without the schema's data-model hints.

#### Scenario: DqZaakDialogen

- **WHEN** the handler chooses Partij toevoegen or Object koppelen
- **THEN** the dialog is titled "Partij toevoegen" or "Object koppelen" and confirms with "Toevoegen" or "Koppelen"
