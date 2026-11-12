# ai-assistance

## ADDED Requirements

### Requirement: The assistant carries out a case action after the handler confirms it (REQ-CRS-24)

The assistant SHALL be able to propose an action on the case, such as a status move or a note. It SHALL carry it out only after the handler confirms, with the handler's own rights, and SHALL record the action as the handler's.

Rows: `12.23` (dossiq matrix).

#### Scenario: A confirmed status move
- **GIVEN** a handler asking the assistant to move the case to In behandeling
- **WHEN** the handler confirms the proposal
- **THEN** the case moves and the history names the handler, marked as done through the assistant
