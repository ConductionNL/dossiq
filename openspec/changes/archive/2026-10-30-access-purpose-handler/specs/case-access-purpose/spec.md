# case-access-purpose Delta

**Status**: proposed
**Scope**: dossiq

## ADDED Requirements

### Requirement: The case's own handler opens it without the dialog

dossiq SHALL answer, for a reader and a case, whether the reader must state a purpose. When the case's case type lists
special categories of personal data and the reader holds one of the configured generic roles on the case (by default
`handler`: the case's assignee, or a role binding whose role type has that generic role), dossiq SHALL record the
configured purpose itself (by default `handling`) with the reader and the time, and answer that no purpose is required.
The case page SHALL ask this before it shows the dialog and SHALL open the case without the dialog when no purpose is
required. The roles and the purpose SHALL be configuration.

#### Scenario: The handler opens their own Melding Jeugdwet case

- **GIVEN** Pieter is the assignee of a Melding Jeugdwet case, whose case type lists health
- **WHEN** Pieter opens the case
- **THEN** the dialog does not show, the case opens, and an audit entry `dossiq.case.access-purpose` with reader pieter, purpose handling and `automatic: true` is on the case

#### Scenario: A colleague who is not the handler

- **GIVEN** Fatma holds no handler role on the case
- **WHEN** Fatma opens the case
- **THEN** the endpoint answers that a purpose is required and the dialog shows

### Requirement: The dialog says why it shows

The dialog SHALL say that it is shown because the reader is not the case's handler.

#### Scenario: The intro

- **WHEN** the dialog shows
- **THEN** its intro says the reader is not this case's handler and that the purpose is recorded with name and time

### Requirement: Handling the case makes you its handler

A purpose SHALL be able to bind the reader to a generic role on the case, by configuration; by default `handling` binds
`handler`. The dialog SHALL say so under that purpose before the reader confirms. After the purpose is recorded, dossiq
SHALL add a role binding for the reader with a role type that has that generic role (the case type's own first, else
one shared by every case type), and SHALL write an audit entry `dossiq.case.access-role` next to the purpose. A reader
who already holds the role SHALL not be bound again, and a case without such a role type SHALL still open with its
purpose recorded.

#### Scenario: Fatma picks "Behandeling van de zaak"

- **GIVEN** the option says "Je wordt behandelaar van deze zaak."
- **WHEN** Fatma confirms "Behandeling van de zaak"
- **THEN** the purpose entry and a `dossiq.case.access-role` entry (role handler, participant user:fatma) are on the case, a role binding names Fatma as handler, and the case opens

#### Scenario: Another purpose

- **WHEN** Fatma confirms "Advies of overleg over de zaak"
- **THEN** the purpose is recorded and no role is added
