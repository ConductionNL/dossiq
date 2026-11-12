# case-management

## ADDED Requirements

### Requirement: A handler links an object to a case from its page (REQ-CRS-26)

The Objects tab of a case SHALL offer Link object. It SHALL ask the object type, the identification and a description, and SHALL save a caseObject on the open case.

Rows: `2.15` (dossiq matrix).

#### Scenario: A building is linked
- **GIVEN** a case without objects
- **WHEN** a handler links the building 0363100012345678
- **THEN** the Objects tab lists that building

### Requirement: An admin reads a case's flow variables and run log (REQ-CRS-27)

An administrator inspecting a case SHALL be able to read, per flow run on that case, the variables the run holds and the log of the steps it took. A handler SHALL NOT be offered this view.

Rows: `2.22` (dossiq matrix).

#### Scenario: A run's variables and steps
- **GIVEN** a case with one finished flow run
- **WHEN** an administrator inspects its flow runs
- **THEN** the run shows its variables and each step it took

### Requirement: A handler looks up an address object and links it to the case (REQ-CRS-32)

A handler SHALL be able to search an address, pick the address object the registry answers, and link it to the case. The link SHALL carry the registry identification of the object.

Rows: `5.10` (dossiq matrix).

#### Scenario: An address is linked
- **GIVEN** a handler on a case
- **WHEN** they search "Molenweg 4" and pick the answer
- **THEN** the case is linked to that address object with its registry identification

### Requirement: An approved destruction list destroys its cases (REQ-CRS-44)

Once a destruction list is approved, every case on it SHALL be destroyed together with its documents and process data. The record SHALL name who approved the list, and a case under a legal hold SHALL be left out.

Rows: `13.10` (dossiq matrix).

#### Scenario: A held case stays
- **GIVEN** an approved destruction list of three cases, one under a legal hold
- **WHEN** the destruction runs
- **THEN** two cases are gone and the held case remains
