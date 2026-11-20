# case-email-integration

## ADDED Requirements

### Requirement: A handler attaches case documents to a mail from the case (REQ-CRS-33)

Composing a mail from a case SHALL let the handler pick documents of that case as attachments. The mail draft SHALL carry the picked documents.

Rows: `6.3` (dossiq matrix).

#### Scenario: Two documents attached
- **GIVEN** a case with a decision and a map
- **WHEN** a handler composes a mail and picks both
- **THEN** the draft carries the decision and the map as attachments
