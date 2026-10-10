## ADDED Requirements

### Requirement: A resident reads dossiq's messages in full in the portal inbox (REQ-PORTAL-005)
The citizen `berichten` inbox collection MUST project `body`, `receivedAt` and
`read` from `portaalBericht`, and MUST declare `filesDownload`, so the shared
portal inbox shows a dossiq message's text, date, read state and attachments.
Marking a message read MUST record when the resident read it.

#### Scenario: A message from the handler shows in full
- **GIVEN** a handler sent a message with one attachment about the resident's
  case
- **WHEN** the resident opens the inbox in the portal
- **THEN** they MUST see the subject, the text, the date and the attachment
- **AND** opening the attachment MUST download it

@e2e exclude Rendered by portaliq's inbox, a sibling repo; dossiq's declarations are pinned by tests/Unit/Portal/PortalConversationTest.php and PortalContributionProviderTest.php.

### Requirement: A reply carries its case without the resident typing it (REQ-PORTAL-006)
The `berichten` collection MUST declare a reply through `replyToMessage` that
carries `caseId` from the message answered. `replyToMessage` MUST offer the
resident's own cases as a choice for `caseId` and MUST accept files as
attachments.

#### Scenario: Reply to a message
- **GIVEN** a message from the handler about case "Kapvergunning Dorpsstraat 4"
- **WHEN** the resident presses Reply, writes a text, adds a photo and sends
- **THEN** the reply MUST be stored on that case with the photo attached
- **AND** the resident MUST NOT have been asked for a case number

@e2e exclude Rendered by portaliq's reply form, a sibling repo; the reply, carry and case choice are pinned by tests/Unit/Portal/PortalConversationTest.php.

### Requirement: A resident asks a question from the case and finds the answer there (REQ-PORTAL-007)
The `mijnZaken` detail MUST offer "Ask a question about this case", which
creates a message on that case, and MUST list the messages about that case in
both directions, newest first, through a `caseMessages` provider that answers
only the resident's own messages.

#### Scenario: Ask on the case page
- **GIVEN** a resident viewing their case in the portal
- **WHEN** they ask "When will I hear back?"
- **THEN** the message MUST be stored on that case
- **AND** the handler's answer MUST appear under Berichten on the same case page

@e2e exclude Rendered by portaliq's case page, a sibling repo; the question and the caseMessages provider are pinned by tests/Unit/Portal/PortalConversationTest.php and PortalCaseMessagesTest.php.

### Requirement: The handler sees a resident's message on the case and answers from it (REQ-PORTAL-008)
A message a resident sends about a case MUST appear on that case's timeline as
an internal portal message entry with a follow-up. The case page MUST let the
handler send a message to the resident of a case with a portal subject, and
Reply on a resident's message. The handler's message MUST reach the resident's
portal inbox and close the follow-up.

#### Scenario: Answer a resident's question from the case
- **GIVEN** a resident asked a question about their case in the portal
- **WHEN** the handler opens the case
- **THEN** the timeline MUST show the question with an open follow-up
- **AND** after the handler presses Reply and sends an answer, the follow-up
  MUST be closed and the answer MUST be in the resident's inbox
