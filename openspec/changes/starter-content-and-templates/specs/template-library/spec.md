## ADDED Requirements

### Requirement: A case starts from a saved case template (REQ-TPL-01)

A handler SHALL start a case from a saved case template that presets its
fields. A case template SHALL be a case row marked as a template, SHALL be
excluded from every working list, count and term calculation, and SHALL
never be worked. Starting from one SHALL record which template was used.

#### Scenario: twelve standaardzaken that differ in four fields
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a saved case template presetting the case type, the group and two fields
- **WHEN** a handler starts a case from it
- **THEN** the new case SHALL carry those values
- **AND** the case SHALL record the template it came from

#### Scenario: a template is not work

- **GIVEN** a case template
- **WHEN** the working list, the open count and the term report are read
- **THEN** the template SHALL appear in none of them

#### Scenario: a template does not start a term

- **GIVEN** a case template for a case type with a statutory term
- **WHEN** the template is saved
- **THEN** no term SHALL be bound to it

### Requirement: The template library covers tasks, notes, approvals and results (REQ-TPL-02)

The template library SHALL carry a `kind` and SHALL hold templates for
documents, mail, tasks, notes, approvals and results. A template of any
kind SHALL be offered where that kind is created, SHALL be searchable by
name, and SHALL be scoped to the case types that may use it.

#### Scenario: a recurring request for advice is typed once
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a task template named Vraag advies aan juridische zaken
- **WHEN** a handler adds a task on a case
- **THEN** the template SHALL be offered
- **AND** choosing it SHALL preset the task title, the group and the lead time

#### Scenario: a result template presets the outcome text
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a result template for a niet-ontvankelijkverklaring
- **WHEN** a handler closes a case with that result
- **THEN** the outcome text SHALL be preset from the template

#### Scenario: a template is scoped to its case types

- **GIVEN** a note template scoped to bezwaar case types
- **WHEN** a handler adds a note on a vergunning case
- **THEN** that template SHALL NOT be offered

### Requirement: A designed process step is reused across case types (REQ-TPL-03)

A process step SHALL be savable as a reusable step and SHALL be referenced
by several case types rather than copied into each. Changing a reusable
step SHALL reach every case type that references it. A case type SHALL be
able to read which reusable steps it uses, and a reusable step SHALL be
able to read which case types use it.

#### Scenario: one step, several processes
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a reusable step Ontvankelijkheidstoets referenced by two case types
- **WHEN** an administrator changes its lead time
- **THEN** both case types SHALL show the new lead time

#### Scenario: a reusable step names its users
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a reusable step used by two case types
- **WHEN** an administrator opens it
- **THEN** it SHALL name both case types

#### Scenario: a reusable step in use is not deleted

- **GIVEN** a reusable step referenced by a published case type
- **WHEN** an administrator deletes it
- **THEN** it SHALL refuse
- **AND** the refusal SHALL name the case type
