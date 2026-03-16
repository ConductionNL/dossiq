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

### Current Implementation Status

**Not yet implemented.** No Mijn Overheid Berichtenbox integration code exists in the Procest codebase. There are no schemas, controllers, services, or Vue components for sending messages to the Berichtenbox.

**Foundation available:**
- Case detail view (`src/views/cases/CaseDetail.vue`) provides the integration point for a "Bericht verzenden" action.
- Activity timeline (`src/views/cases/components/ActivityTimeline.vue`) could display message sent/read events.
- Document management (filesPlugin in object store) could store sent messages as case documents.
- The `dispatch_schema` exists in `SettingsService::SLUG_TO_CONFIG_KEY`, which could be used for message dispatch tracking.
- Docudesk (external dependency) provides PDF generation for message archival.
- OpenConnector could host the Berichtenbox API adapter.
- `NotificatieService` (`lib/Service/NotificatieService.php`) provides notification infrastructure.

**Partial implementations:** None.

**Mock Registers (dependency):** This spec depends on mock BRP registers being available in OpenRegister for development and testing of BSN-based message sending. These registers are available as JSON files that can be loaded on demand from `openregister/lib/Settings/`. Production deployments should connect to the actual Haal Centraal BRP API via OpenConnector.

### Using Mock Register Data

This spec depends on the **BRP** mock register for BSN-based citizen identification and message sending.

**Loading the register:**
```bash
# Load BRP register (35 persons, register slug: "brp", schema: "ingeschreven-persoon")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/brp_register.json
```

**Test data for this spec's use cases:**
- **Send message to citizen**: BSN `999993653` (Suzanne Moulin) -- test message composition with valid BSN linked to case
- **Reject without BSN**: Create a case without BSN, verify error "BSN is verplicht voor berichten via Mijn Overheid"
- **Multiple citizens**: BSN `999990627` (Stephan Janssen), BSN `999992570` (Albert Vogel) -- test message sending to different persons
- **Deceased person edge case**: BSN `999999655` (Astrid Abels, deceased 2020-06-06) -- test handling of messages to deceased persons

**Querying mock data:**
```bash
# Find person by BSN for case linking
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{brp_register_id}/{person_schema_id}?_search=999993653" -u admin:admin
```

### Standards & References

- **Mijn Overheid Berichtenbox API**: Government-mandated citizen correspondence channel operated by Logius. Uses SOAP/REST with mTLS certificate authentication.
- **OIN (Organisatie-identificatienummer)**: Required for government API authentication with Mijn Overheid.
- **PKIoverheid**: Certificate infrastructure for mTLS authentication.
- **Digikoppeling**: Dutch government standard for system-to-system communication (may be required for Berichtenbox).
- **AVG/GDPR**: BSN processing requires lawful basis and secure handling.
- **Wet digitale overheid (Wdo)**: Legislation mandating digital government communication channels.
- **BRP**: BSN lookup for citizen identification.

### Specificity Assessment

This spec covers the essential requirements for Mijn Overheid integration with clear scenarios for sending, read tracking, and format compliance.

**What's missing:**
- No specification of the Berichtenbox API endpoint structure (SOAP WSDL or REST endpoint URLs).
- No specification of how the OIN and PKIoverheid certificate are configured and stored in Nextcloud.
- No specification of the message composer UI component.
- No data model for bericht type codes (how they're stored per zaaktype).
- No specification of the read status polling background job implementation.
- No specification of error handling for API failures (retry logic, failure notifications).
- No specification of message character limits per Mijn Overheid specifications.

**Open questions:**
1. Should the integration use the SOAP or REST variant of the Berichtenbox API?
2. How is the mTLS certificate managed in the Nextcloud environment (file-based or database)?
3. Should the system support sending to organizations via eHerkenning in addition to citizens via BSN?
4. What is the expected polling frequency for read status, and how long should polling continue?
5. Should the system support bulk message sending (e.g., status notification to all cases of a type)?
