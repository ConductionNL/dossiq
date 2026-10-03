## ADDED Requirements

### Requirement: A case carries a handler and a coordinator (REQ-HAND-05)

A case SHALL carry two named seats: the handler doing the work, which
remains `assignee`, and a coordinator answerable for it, bound as a role
on the case rather than a second assignee field. Both SHALL be assignable,
both SHALL be searchable, and both SHALL be shown on the case's people
panel.

#### Scenario: behandelaar and casemanager are two people
@e2e tests/e2e/handing-a-case-over.spec.ts

- **GIVEN** a case
- **WHEN** a teamleider names a handler and a coordinator
- **THEN** the case SHALL carry both
- **AND** the people panel SHALL show both with their roles

#### Scenario: a coordinator finds their cases
@e2e tests/e2e/handing-a-case-over.spec.ts

- **GIVEN** a person who is coordinator on four cases and handler on none
- **WHEN** they search their cases
- **THEN** those four SHALL be found

#### Scenario: a handover keeps the seats it can

- **GIVEN** a case with a handler and a coordinator
- **WHEN** it is handed to another team
- **THEN** any seat whose holder is not in the receiving team SHALL be emptied and recorded
- **AND** the emptying SHALL be visible on the case

### Requirement: A case type may require a coordinator before signing (REQ-HAND-06)

A case type SHALL be able to declare that a coordinator is required before
a besluit is signed. Where it is declared and the seat is empty, the
signing act SHALL be refused with a 4xx carrying `{message, error}` naming
the rule. A case type that does not declare it SHALL never ask for a
coordinator.

#### Scenario: the Awb answer is signed by the second seat
@e2e tests/e2e/handing-a-case-over.spec.ts

- **GIVEN** a case type requiring a coordinator before signing, and an empty seat
- **WHEN** a handler signs the besluit
- **THEN** it SHALL be refused
- **AND** the refusal SHALL name the coordinator requirement

#### Scenario: with the seat filled it signs
@e2e tests/e2e/handing-a-case-over.spec.ts

- **GIVEN** the same case type with a coordinator named
- **WHEN** the besluit is signed
- **THEN** it SHALL be accepted

#### Scenario: a melding asks for nobody

- **GIVEN** a case type with no coordinator requirement
- **WHEN** a handler closes a case of that type
- **THEN** no coordinator SHALL be asked for

### Requirement: Everything a leaver holds moves in one act (REQ-HAND-07)

dossiq SHALL offer one act that moves everything a person holds to another
person or team: cases where they are the handler, cases where they are the
coordinator, their open tasks, and their drafts. The act SHALL be previewed
before it runs, naming what will move and how much. It SHALL record who
ran it, when, what moved and where from. It SHALL listen for an
offboarding signal from humaniq when one exists, and until then SHALL be
started by an administrator naming the person.

#### Scenario: uitdiensttreding is one act
@e2e tests/e2e/handing-a-case-over.spec.ts

- **GIVEN** a person holding nine cases, three coordinator seats, four tasks and two drafts
- **WHEN** an administrator hands their work to a colleague
- **THEN** all eighteen SHALL move
- **AND** the act SHALL be recorded with who ran it

#### Scenario: it is previewed before it runs
@e2e tests/e2e/handing-a-case-over.spec.ts

- **GIVEN** an administrator starting a leaver handover
- **WHEN** they open it
- **THEN** it SHALL name what will move and how much
- **AND** nothing SHALL move until they confirm

#### Scenario: a draft does not become unreadable
@e2e tests/e2e/handing-a-case-over.spec.ts

- **GIVEN** a draft case private to a person who has left
- **WHEN** their work is handed over
- **THEN** the draft SHALL be readable by the receiving person

#### Scenario: what moved is traceable afterwards

- **GIVEN** a completed leaver handover
- **WHEN** a case that moved is opened
- **THEN** it SHALL record that it moved, from whom, and by whose act
