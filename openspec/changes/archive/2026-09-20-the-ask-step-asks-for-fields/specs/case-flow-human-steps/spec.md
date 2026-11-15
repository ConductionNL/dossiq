# case-flow-human-steps

## ADDED Requirements

### Requirement: An ask may ask for fields, and the engine resolves them

A `dossiq.askPerson` step SHALL accept an optional form declaration in the
shape OpenRegister's FLOW NODE contract declares, which is the six flat keys
`TaskFormReader::fromConfig()` reads:

- `formKind: "fields"`, naming the subject `formSchema` and an ordered
  `formFields` list of `{field, required}` entries, or a `formAction` to
  inherit the fields from; or
- `formKind: "external"`, naming a `formId` bound through the engine's form
  link.

The keys SHALL be flat, and this is not a style choice. The nested `form`
block `OCA\Dossiq\Service\Task\TaskDeclaration` reads belongs to the
TRANSITION path, where it is written to the task's `metadata.form` and read by
`TaskFormReader::fromRecord()`. A flow node's task carries no such block: the
engine resolves its form from the run's pinned graph through
`TaskFormResolver::declarationOf()`, which reads `node['config']` through
`fromConfig()`. A nested block on an ask step would leave `formKind` absent,
resolve to a declaration with no form, and hand the assignee a task with no
fields and no error anywhere.

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

- **GIVEN** a flow whose ask step declares `formKind: "fields"` over the case schema with `verslag` required
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

- **GIVEN** a flow whose ask step declares no form key
- **WHEN** the assignee opens the task
- **THEN** the task SHALL show the question and no fields
- **AND** completing it SHALL be accepted with no payload

### Requirement: A form the performer could not fill is refused while it is being saved

`DossiqAskPersonNode::validateConfig()` SHALL refuse a `form` declaration that
cannot render, and the refusal SHALL name the schema, the field and the
reason. It SHALL refuse:

- a `formKind` that is neither `fields` nor `external`;
- a `fields` declaration naming no schema, or an empty field list;
- a form key orphaned from any `formKind`, which is the shape an author lands
  in by copying the transition path's nested block;
- a field that is not a property of the named schema;
- a field the schema marks read only, or marks invisible;
- an `external` declaration naming no form.

The refusals SHALL be OpenRegister's own, forwarded unchanged. dossiq SHALL
NOT paraphrase them: an author who reads one wording here and another in
openregister can search for neither.

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
