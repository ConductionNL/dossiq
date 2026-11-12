# federated-case-collaboration

## ADDED Requirements

### Requirement: A transfer hands the case over, and the sender follows its activity (REQ-CRS-42)

Choosing Overdragen SHALL make the other organisation the owner of the case. The sending organisation SHALL keep reading the case and SHALL see each act the receiver records on it.

Rows: `12.28` (dossiq matrix).

#### Scenario: The receiver adds a document
- **GIVEN** a case transferred to another organisation
- **WHEN** the receiver adds a document
- **THEN** the sender sees "document added" on the case
