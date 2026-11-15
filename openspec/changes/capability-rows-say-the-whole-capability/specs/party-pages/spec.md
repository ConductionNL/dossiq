# party-pages

## ADDED Requirements

### Requirement: The contact page lists the contact's documents and communication (REQ-CRS-04)

The contact detail page SHALL list, beside the contact's cases, the documents linked to the contact and the contact moments and messages exchanged with them, newest first. Each row SHALL open the document or the moment it names.

Rows: `5.4` (dossiq matrix).

#### Scenario: A contact with a letter and a phone call
- **GIVEN** a contact with one linked document and one logged phone call
- **WHEN** you open the contact page
- **THEN** the documents list shows the letter and the communication list shows the phone call
