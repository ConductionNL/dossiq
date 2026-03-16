# mijn-overheid-integration Specification

## Purpose
Send official government messages to the national Mijn Overheid Berichtenbox from within Procest case context. Mijn Overheid is the government-mandated channel for official citizen correspondence. Messages follow strict format requirements and support read tracking.

## Context
Dutch municipalities are increasingly required to send official correspondence (beschikkingen, status updates, decision notifications) through the Mijn Overheid Berichtenbox rather than postal mail. This integration enables Procest case workers to send messages directly from a case, with the message and any attachment stored as case documents for the audit trail.

## ADDED Requirements

### Requirement: Send message to Berichtenbox
The system MUST support sending messages to a citizen's Mijn Overheid Berichtenbox from within a case.

#### Scenario: Send a simple text message
- GIVEN a case with a linked BSN (burgerservicenummer)
- WHEN the case worker composes a message with subject "Besluit vergunningaanvraag" and body text
- THEN the system MUST send the message to the Mijn Overheid Berichtenbox API
- AND the message MUST be stored as a case document (PDF format)
- AND the audit trail MUST record the send action with timestamp, user, and message reference

#### Scenario: Send message with PDF attachment
- GIVEN a case with a linked BSN
- WHEN the case worker attaches a single PDF document to the message
- THEN the system MUST send the message with the PDF as attachment
- AND the attachment MUST NOT exceed 10 MB
- AND only a single PDF attachment is permitted per message (Mijn Overheid limitation)

#### Scenario: Reject message without BSN
- GIVEN a case without a linked BSN
- WHEN the case worker attempts to send a Berichtenbox message
- THEN the system MUST display an error: "BSN is verplicht voor berichten via Mijn Overheid"
- AND the message MUST NOT be sent

### Requirement: Bericht type codes
The system MUST support bericht type codes for message routing and categorization.

#### Scenario: Select bericht type on send
- GIVEN a configured set of bericht type codes per zaaktype
- WHEN the case worker composes a message
- THEN a bericht type dropdown MUST be displayed with available codes
- AND the selected type code MUST be included in the API call

#### Scenario: Default bericht type per zaaktype
- GIVEN a zaaktype with a configured default bericht type code
- WHEN the case worker opens the message composer
- THEN the bericht type MUST be pre-selected with the configured default
- AND the case worker MUST be able to override the default

### Requirement: Read tracking
The system MUST track whether the citizen has read the message.

#### Scenario: Message read status polling
- GIVEN a sent message with reference ID
- WHEN the system polls the Berichtenbox API for read status
- THEN the case document MUST be updated with the read timestamp when confirmed
- AND the case timeline MUST show "Bericht gelezen door burger" with the read timestamp

#### Scenario: Unread message after 7 days
- GIVEN a sent message that remains unread for 7 days
- WHEN the polling job detects the threshold is exceeded
- THEN the system SHOULD flag the message as "niet gelezen" in the case timeline
- AND the case worker SHOULD receive a notification

### Requirement: Message format compliance
Messages MUST comply with Mijn Overheid Berichtenbox format requirements.

#### Scenario: Plain text enforcement
- GIVEN a case worker composing a message
- WHEN they enter the message body
- THEN the editor MUST be plain text only (no HTML, no rich text)
- AND the character limit MUST be enforced per Mijn Overheid specifications

#### Scenario: Required fields validation
- GIVEN a message being composed
- WHEN the case worker attempts to send
- THEN the system MUST validate that subject, body, BSN, and bericht type are present
- AND missing fields MUST be highlighted with validation errors

### Requirement: Admin configuration
Administrators MUST be able to configure the Mijn Overheid connection.

#### Scenario: Configure API credentials
- GIVEN the Procest admin settings
- WHEN the admin enters the Mijn Overheid API endpoint URL, organization certificate, and OIN
- THEN the system MUST store the credentials securely
- AND a "Test connection" button MUST verify connectivity

#### Scenario: Configure bericht type codes per zaaktype
- GIVEN the zaaktype configuration screen
- WHEN the admin adds bericht type codes with labels
- THEN the codes MUST be available in the message composer for cases of that type

## Dependencies
- Mijn Overheid Berichtenbox API (SOAP/REST, mTLS certificate authentication)
- BSN field on case (or linked person record in OpenRegister)
- Docudesk for PDF generation of sent messages
- Nextcloud background jobs for read status polling
