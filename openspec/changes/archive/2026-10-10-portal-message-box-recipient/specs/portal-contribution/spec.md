## ADDED Requirements

### Requirement: The inbox names the message box recipient (REQ-PORTAL-019)
The `berichten` inbox MUST declare `messageBox: {recipientProvider: "messageBoxRecipient"}`.
`messageBoxRecipient(messageId)` MUST return the applicant's BSN only for an
organisation's message on a case whose applicant is a person with a valid BSN
and to whom the message is addressed, and null for every other message.

#### Scenario: A letter to the applicant reaches their message box
- **GIVEN** a handler's message on a case filed by a resident with a valid BSN, addressed to that resident
- **WHEN** portaliq asks who receives it in the message box
- **THEN** the answer MUST be that resident's BSN

#### Scenario: A reply or a message to a representative names nobody
- **GIVEN** a resident's own reply, or a message addressed to someone other than the applicant
- **WHEN** portaliq asks who receives it
- **THEN** the answer MUST be null and nothing MUST be sent
