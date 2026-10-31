# document-projection Delta: case-folder-lookup-survives-a-parallel-create

**Status**: draft
**Scope**: dossiq's lookup of an object's folder in the register tree.

## ADDED Requirements

### Requirement: A case folder lookup MUST survive a parallel create

When OpenRegister answers a folder lookup with a lock, dossiq SHALL ask again after a short wait, at most three times in all. A lock that outlasts those asks SHALL be reported as a busy folder, which a read endpoint answers with 503. A locked folder SHALL never be read as an object without a folder, and SHALL never answer 500.

#### Scenario: Two requests open a new case's documents at once

- **GIVEN** a case with no folder yet
- **AND** a parallel request is creating that folder
- **WHEN** a handler's Documents tab asks for the linked documents
- **THEN** the lookup waits, finds the folder the other request made, and answers 200

#### Scenario: A folder that stays locked

- **GIVEN** a case folder that stays locked for every ask
- **WHEN** the linked documents are asked for
- **THEN** the answer is 503, after three asks
