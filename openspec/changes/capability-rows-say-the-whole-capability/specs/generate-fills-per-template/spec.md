# generate-fills-per-template

## ADDED Requirements

### Requirement: An administrator lists, adds and edits document templates (REQ-CRS-11)

The template library SHALL let an administrator list the document templates, add a new one and edit an existing one. A saved template SHALL be offered in the generate dialog from then on.

Rows: `11.11` (dossiq matrix).

#### Scenario: A new template is offered
- **GIVEN** an administrator who adds the template "Ontvangstbevestiging"
- **WHEN** a handler opens the generate dialog on a case
- **THEN** the template is offered
