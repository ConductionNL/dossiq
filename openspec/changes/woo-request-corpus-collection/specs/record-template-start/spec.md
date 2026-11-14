# record-template-start

Generic capability (decision 182: procedures are configuration, code is generic). A form in dossiq
can start from a named record template of its schema. The templates live in OpenRegister
(`records-saved-templates`, `/api/record-templates`); dossiq keeps no template store. The search
plan of a request is the first form that uses it. Nothing here belongs to one procedure.

## ADDED Requirements

### Requirement: A named record template is read as the caller (REQ-RTS-001)

`RecordTemplateReader::find()` SHALL read one OpenRegister record template by uuid for the
signed-in caller and SHALL answer its `uuid`, `name` and `values` only when the template is for the
schema the reader was asked about. OpenRegister decides who may use a template. A template that is
absent, that the caller may not use, or that is for another schema SHALL read as no template.
Without a signed-in caller the reader SHALL answer no template and SHALL NOT ask OpenRegister. Any
other failure SHALL be raised, never answered as no template.

#### Scenario: A handler reads a template they may use
- **GIVEN** a public record template "Ruimtelijke verzoeken" for schema 42
- **WHEN** a signed-in handler's code reads it for schema 42
- **THEN** the answer SHALL hold its uuid, its name and its values
- **AND** OpenRegister SHALL have been asked as that handler

#### Scenario: A template the caller may not use reads as absent
- **GIVEN** OpenRegister answers 404 for a template
- **WHEN** it is read
- **THEN** the answer SHALL be no template

#### Scenario: A template of another schema is not used
- **GIVEN** a record template for schema 42
- **WHEN** it is read for schema 43
- **THEN** the answer SHALL be no template

#### Scenario: A failing store is not an absent template
- **GIVEN** OpenRegister fails with a status other than 404
- **WHEN** a template is read
- **THEN** the failure SHALL reach the caller

### Requirement: A form offers the named templates of its schema (REQ-RTS-002)

`RecordTemplateSelect` SHALL list the templates of one schema from
`GET /apps/openregister/api/record-templates?schema=<id>` (`results`, each with `uuid`, `name` and
`values`) and SHALL hand the chosen template to the form that mounts it. It SHALL draw nothing when
the schema is unknown, when there is no template, or when the list cannot be read. The schema id
SHALL be passed as the register configuration holds it, because OpenRegister compares it as text
with what the template was saved under.

#### Scenario: The form shows the templates the user may use
- **GIVEN** OpenRegister answers one template for schema 42
- **WHEN** a form for schema 42 opens
- **THEN** the form SHALL offer that template under "Start from a template"
- **AND** choosing it SHALL hand its values to the form

#### Scenario: No template, no control
- **GIVEN** OpenRegister answers no template, or an error
- **WHEN** the form opens
- **THEN** the form SHALL show no template control and SHALL work as before
