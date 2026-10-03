## ADDED Requirements

### Requirement: REQ-PORTAL-005: An inbox collection MUST name the fields that carry its message

Every `kind: inbox` collection dossiq contributes SHALL declare `messageFields`, naming which of its schema's fields carry the inbox's text, date, read date and attachments. Each named field SHALL exist on the schema and, when the collection declares `fields`, SHALL be among them, because portaliq projects before it maps. The citizen `berichten` collection SHALL declare `filesDownload: true`, so the files a handler attaches to a message reach the resident.

#### Scenario: A handler's message shows its text, date and files
- **GIVEN** a `portaalBericht` to a resident with `content`, `sentAt` and an attached file
- **WHEN** the resident opens their portal inbox
- **THEN** the message shows its text, its date, unread, and the file as a download

#### Scenario: Every named field reaches portaliq
- **GIVEN** the inbox collections of every audience
- **WHEN** each `messageFields` entry is checked against the schema and the `fields` whitelist
- **THEN** every named field exists on the schema and survives the projection
