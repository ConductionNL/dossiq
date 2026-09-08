## ADDED Requirements

### Requirement: You copy a case from its page (REQ-CM-24)

You copy a case with its type, requester and properties into a new case. The
Actions menu of `CaseDetail` SHALL offer Copy case. Confirming SHALL create a
new case of the same type in the type's initial status, carrying the source's
requester, confidentiality, priority, intake channel and properties, with the
source listed under related cases. The number, deadline, result, status
history, decisions and publications SHALL NOT be copied. When you tick Include
documents, the source's open documents SHALL be linked to the new case, not
duplicated.

**Feature tier**: V1

#### Scenario: A handler copies a case
@e2e tests/e2e/case-actions-menu.spec.ts

- **GIVEN** a case of type Melding openbare ruimte with a requester and two filled properties
- **WHEN** the handler chooses Copy case and confirms with the proposed title
- **THEN** a new case SHALL open with the same type, requester and property values
- **AND** its status SHALL be the type's initial status
- **AND** its number SHALL differ from the source's number
- **AND** the source SHALL be listed under its related cases

#### Scenario: Documents come along as links
@e2e tests/e2e/case-actions-menu.spec.ts

- **GIVEN** a case with one document
- **WHEN** the handler copies it with Include documents ticked
- **THEN** the new case's Documents tab SHALL list that document
- **AND** the file SHALL exist once in storage

#### Scenario: A reader cannot copy
@e2e tests/e2e/case-actions-menu.spec.ts

- **GIVEN** a user who may read the case but not write cases
- **WHEN** they post to the copy endpoint
- **THEN** the answer SHALL be 403
- **AND** no case SHALL be created
