# inspection-checklists

## ADDED Requirements

### Requirement: An inspector carries out an inspection without a connection (REQ-CRS-45)

An inspector SHALL be able to fill in a checklist and take photos without a connection. The answers and photos SHALL be kept on the device and sent when the connection returns.

Rows: `16.2` (dossiq matrix).

#### Scenario: No signal on site
- **GIVEN** an inspector who loses the connection on site
- **WHEN** they finish the checklist and take two photos
- **THEN** the answers and photos reach the case once the connection returns
