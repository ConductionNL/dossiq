## ADDED Requirements

### Requirement: A message goes round trip between the handler and the resident (REQ-PORTAL-RT-01)

A message a handler sends from the case SHALL appear in the resident's portal inbox with its body, date and attachments, the resident's reply SHALL appear on the case's contact tab as a contact moment, and the handler's message SHALL show as read once the resident opened it.

#### Scenario: Send, read, reply

- **GIVEN** a case with a portal subject
- **WHEN** the handler sends "Wilt u de tekening aanvullen?" with one attachment, the resident opens it and replies
- **THEN** the handler's message SHALL show as read
- **AND** the reply SHALL be listed on the case's contact tab
- @e2e tests/e2e/portal-conversation-on-the-case.spec.ts
