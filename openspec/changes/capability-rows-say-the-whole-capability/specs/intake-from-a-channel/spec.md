# intake-from-a-channel

## ADDED Requirements

### Requirement: A document in the intake queue can be rejected with a reason (REQ-CRS-01)

A handler SHALL be able to reject a document waiting in the intake queue. The rejection SHALL ask for a reason, SHALL take the document out of the queue, and SHALL record who rejected it, when and why. A rejected document SHALL NOT be filed on any case.

Rows: `1.4` (dossiq matrix).

#### Scenario: A handler rejects a document
- **GIVEN** a scanned letter waiting in the intake queue
- **WHEN** a handler rejects it with the reason "not for this organisation"
- **THEN** the letter leaves the queue and the record names the handler, the time and the reason

### Requirement: The organisation's mailbox lists every unfiled message and files one on a case (REQ-CRS-18)

The organisation SHALL have one mailbox page listing the messages that belong to no case yet, from every channel. A handler SHALL be able to file a message from that list on a case they pick, after which it leaves the list.

Rows: `6.5` (dossiq matrix).

#### Scenario: A handler files a message
- **GIVEN** an e-mail in the mailbox that quotes no case
- **WHEN** a handler files it on case ZAAK-2026-000142
- **THEN** the message is on that case and gone from the mailbox

### Requirement: A case can be started from a Word or Excel document (REQ-CRS-25)

A user SHALL be able to send an open Word or Excel document to intake from the Office application. The document SHALL arrive as a case, or in the intake queue when no routing rule matches.

Rows: `1.9` (dossiq matrix).

#### Scenario: A Word document becomes a case
- **GIVEN** a routing rule that sends documents titled "Aanvraag" to the permit case type
- **WHEN** a user sends an open Word document titled "Aanvraag dakkapel"
- **THEN** a permit case exists with the document attached
