# case-flow-human-steps

## ADDED Requirements

### Requirement: An ask may ask for fields, and the engine resolves them

A `dossiq.askPerson` step SHALL accept an optional `form` block in the shape
OpenRegister's task form contract declares, the same shape
`OCA\Dossiq\Service\Task\TaskDeclaration` already reads for a task raised by a
status transition:

- `kind: "fields"`, naming the subject `schema` and an ordered `fields` list of
  `{field, required}` entries; or
- `kind: "external"`, naming a Nextcloud Forms form bound through the engine's
  form link.

The step SHALL NOT carry a second form vocabulary, and dossiq SHALL NOT copy
the declaration onto the task. An ask task already carries the node that
created it and the run it belongs to, so the engine SHALL resolve the form
through the flow definition version that run is pinned to. Editing or
publishing the flow afterwards SHALL change the form of no open task.

A step that declares no `form` SHALL behave exactly as it did before: a task
with a question, a description, an assignee and a due date, and nothing to
fill in.

**Feature tier**: MVP

#### Scenario: A declared field reaches the person who has to answer it
@e2e tests/e2e/ask-step-form.spec.ts

- **GIVEN** a flow whose ask step declares `kind: "fields"` over the case schema with `verslag` required
- **WHEN** the assignee opens the task the run created
- **THEN** the task SHALL show the `verslag` field
- **AND** completing the task without filling it SHALL be refused naming that field

#### Scenario: A published edit leaves an open task alone
@e2e tests/e2e/ask-step-form.spec.ts

- **GIVEN** an open ask task raised by version 3 of its flow, whose step declared one field
- **WHEN** an author adds a second field to that step and publishes version 4
- **THEN** the open task SHALL still present version 3's single field

#### Scenario: A step with no form is unchanged
@e2e tests/e2e/ask-step-form.spec.ts

- **GIVEN** a flow whose ask step declares no `form`
- **WHEN** the assignee opens the task
- **THEN** the task SHALL show the question and no fields
- **AND** completing it SHALL be accepted with no payload

### Requirement: A form the performer could not fill is refused while it is being saved

`DossiqAskPersonNode::validateConfig()` SHALL refuse a `form` declaration that
cannot render, and the refusal SHALL name the schema, the field and the
reason. It SHALL refuse:

- a `kind` that is neither `fields` nor `external`;
- a `fields` declaration naming no schema, or an empty field list;
- a field that is not a property of the named schema;
- a field the schema marks read only, or marks invisible;
- an `external` declaration naming no form.

The refusal SHALL reach the author at save time. A declaration that only
fails when the task opens lands on the performer, who can neither fill the
field nor skip it, and who is not the person who can fix it.

**Feature tier**: MVP

#### Scenario: A field the schema does not have is refused at save
@e2e tests/e2e/ask-step-form.spec.ts

- **GIVEN** an author editing an ask step that declares the field `verslagje` over the case schema
- **WHEN** they save the flow
- **THEN** the save SHALL be refused
- **AND** the message SHALL name the case schema, the field `verslagje` and the reason

#### Scenario: A read-only field is refused at save
@e2e tests/e2e/ask-step-form.spec.ts

- **GIVEN** an author declaring the generated field `identifier` on an ask step
- **WHEN** they save the flow
- **THEN** the save SHALL be refused naming the field and the fact that nobody can write it
