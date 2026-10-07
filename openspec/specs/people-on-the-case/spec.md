# people-on-the-case Specification

## Purpose
A person on a case is an OpenRegister person link, not a field dossiq keeps on its own. The person is a Nextcloud user or a vCard contact, the link's role is one of the role types this instance declares, and the `role` record on the case is the projection of that link. An initiator link names the requester on the case, the People tab shows who is on it, and a file request is addressed to one of those people. This capability covers how those links are read, projected and shown.

## Requirements

### Requirement: REQ-POC-001 A person linked to a case SHALL become a role record on that case

When OpenRegister announces a person link on a case object, dossiq SHALL keep one `role` record per link: `case` the case, `roleType` the role type the link's role names, `participant` the person's uid (`user:<uid>` for a Nextcloud user, the vCard uid for a contact), `name` the person's display name and `description` the link's note. A changed link SHALL update that record and an unlinked person SHALL remove it. A role record the projection did not write SHALL be left alone, so seed data and hand-made roles survive.

#### Scenario: REQ-POC-001a Linking a user writes the role record
@e2e tests/e2e/case-people-on-the-case.spec.ts

- **GIVEN** a case whose schema declares its role types as its link vocabulary
- **WHEN** a handler links user `jan` in the role type named Behandelaar
- **THEN** a `role` record MUST exist with that case, that role type, `participant` `user:jan` and `name` the user's display name
- **AND** the Parties section of the People tab MUST show the person

#### Scenario: REQ-POC-001b Unlinking removes the record it wrote
- **GIVEN** the link above
- **WHEN** the link is removed
- **THEN** the `role` record MUST be gone
- **AND** a `role` record that names no linked person MUST be untouched

#### Scenario: REQ-POC-001c A link whose role names no role type writes nothing
- **GIVEN** a link whose role is not the uuid of a role type
- **WHEN** the projection runs
- **THEN** no `role` record MUST be created and the reason MUST be logged

### Requirement: REQ-POC-002 The case schema SHALL declare the instance's role types as its link vocabulary

`configuration.linkRoles` on the case schema SHALL carry one entry per published role type, keyed by the role type's uuid and labelled by its name. It SHALL be written by a repair step and again after a role type is saved, so the picker offers the role types this instance has. Role types of every case type SHALL be in the one vocabulary, because one case schema serves them all.

#### Scenario: REQ-POC-002a The vocabulary follows the role types
- **GIVEN** three published role types across two case types
- **WHEN** the sync runs
- **THEN** the case schema's `linkRoles` MUST hold three entries, keyed by uuid and labelled by name
- **AND** a role type added later MUST appear after the next save

#### Scenario: REQ-POC-002b A role outside the vocabulary is refused at the source
- **GIVEN** the vocabulary above
- **WHEN** a link is posted with a role that is not one of those keys
- **THEN** OpenRegister MUST refuse it with 400 and no `role` record MUST appear

### Requirement: REQ-POC-003 An initiator link SHALL name the requester on the case

A link whose role type carries the generic role `initiator` SHALL fill `initiatorDisplayName` with the person's display name and `initiatorSourceId` with their uid, and SHALL clear both when the link is removed. `requester` and `initiatorType` SHALL NOT be written by the projection: a user or a contact is not a row in the requester register.

#### Scenario: REQ-POC-003a The case lists the linked initiator by name
@e2e tests/e2e/case-people-on-the-case.spec.ts

- **GIVEN** a case with no requester
- **WHEN** a person is linked in the initiator role type
- **THEN** `initiatorDisplayName` MUST be that person's name and the case list's Requester column MUST show it
- **AND** `requester` MUST still be empty

#### Scenario: REQ-POC-003b Removing the initiator link clears the name
- **WHEN** the initiator link is removed
- **THEN** `initiatorDisplayName` and `initiatorSourceId` MUST be empty
- **AND** a case whose requester was picked the old way MUST keep it

### Requirement: REQ-POC-004 The People tab SHALL show the people on the case

The Parties section SHALL render the contacts integration on the case object, so the people are grouped by role type with their avatar, email, validity window and note, and linking a person offers Nextcloud users and contacts.

#### Scenario: REQ-POC-004a Parties lists people, not identifiers
@e2e tests/e2e/case-people-on-the-case.spec.ts

- **GIVEN** a case with two people linked in different role types
- **WHEN** the handler opens the People tab
- **THEN** the Parties section MUST show both under their role type's name, with their display names

### Requirement: REQ-POC-005 A file request SHALL be addressed to a party of the case

The Files tab's New menu SHALL offer "Request a file from a party". The dialog SHALL list the people on the case, SHALL disable a person with no email address and say why, and on confirmation SHALL create an email share of the case folder with create-only permission, carrying an optional note and expiry. What the party uploads lands in the case folder and becomes a document of the case through `document-projection`.

#### Scenario: REQ-POC-005a The request names a person of the case
@e2e tests/e2e/case-people-on-the-case.spec.ts

- **GIVEN** a case with a linked person who has an email address
- **WHEN** the handler chooses "Request a file from a party" and picks that person
- **THEN** an email share of the case folder MUST be created for that address with create permission and no read permission
- **AND** the dialog MUST report the recipient it sent to

#### Scenario: REQ-POC-005b A person with no address is shown and disabled
- **GIVEN** a case with a linked person who has no email address
- **WHEN** the dialog lists the people
- **THEN** that person MUST be listed, not selectable, with the reason beside them

#### Scenario: REQ-POC-005c A person who is not on the case is refused
- **WHEN** a request names a person with no link to the case
- **THEN** the response MUST be 404 and no share MUST be created

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
