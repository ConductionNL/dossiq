# generate-fills-per-template Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [dialogs-answer-what-the-boards-draw](../../)

## ADDED Requirements

### Requirement: A template carries notes for the generate dialog

The template list SHALL answer each template's `notes` (strings, empty when the template has none).

#### Scenario: Woo decision

- **WHEN** the generate dialog lists the templates
- **THEN** the Woo decision templates carry their note about the objection period
