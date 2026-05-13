---
status: implemented
---
# case-email-integration Specification

## Purpose
Send and receive email from within case context. Emails are converted to PDF and stored as case documents, creating a complete communication audit trail. Template variables from case data enable consistent correspondence.

## Context
Email remains a primary communication channel between municipalities and citizens/organizations. Currently, email communication happens outside the case system, making it impossible to reconstruct the full communication history. This spec integrates email directly into the case workflow: outbound emails use templates with case data, and all sent/received emails are archived as case documents. The integration leverages Nextcloud Mail app infrastructure where available, with a fallback to direct SMTP/IMAP for standalone deployments. All email data is stored as OpenRegister objects under the Procest register using dedicated schemas (`emailTemplate`, `emailMessage`, `emailThread`).

## ADDED Requirements
### Requirement: Send email from case context
The system MUST support sending email from within a case, with the email stored as a case document and recorded in the activity timeline.

#### Scenario: Send email with case template
- **GIVEN** a case of type "Omgevingsvergunning" with configured email templates
- **WHEN** the case worker selects template "Ontvangstbevestiging" and clicks send
- **THEN** template variables (`{{zaakNummer}}`, `{{aanvragerNaam}}`, `{{startdatum}}`) MUST be resolved from case data
- **AND** the email MUST be sent to the case's primary contact email address
- **AND** a PDF copy of the sent email MUST be created via Docudesk and linked as a case document (schema `caseDocument`)
- **AND** the case activity array MUST receive an entry of type `email_sent` with description "Email verzonden: Ontvangstbevestiging"

#### Scenario: Send ad-hoc email without template
- **GIVEN** a case with a linked contact email
- **WHEN** the case worker composes a free-form email with subject and body
- **THEN** the email MUST be sent with the municipality's configured from-address (stored in `IAppConfig` under key `email_from_address`)
- **AND** the case identifier MUST be included in the email subject as a prefix (e.g., "[ZAAK-2026-001234] Uw aanvraag")
- **AND** the sent email MUST be stored as a `caseDocument` object linked to the case

#### Scenario: Send email with case document attachments
- **GIVEN** a case with existing documents stored in OpenRegister
- **WHEN** the case worker selects documents from the case's document list to attach
- **THEN** each selected document MUST be retrieved from Nextcloud Files via `IRootFolder` and attached to the email
- **AND** the total attachment size MUST NOT exceed the configured limit (default: 25 MB, stored in `IAppConfig` under key `email_max_attachment_size`)
- **AND** if the size limit is exceeded, the UI MUST display a validation error before attempting to send

#### Scenario: Send email with CC and BCC recipients
- **GIVEN** a case with multiple participants (stored as `role` objects)
- **WHEN** the case worker adds CC or BCC recipients from the participant list or by typing email addresses
- **THEN** the email MUST be sent to all specified recipients
- **AND** all recipients MUST be recorded in the stored email message object

#### Scenario: Prevent sending from closed case
- **GIVEN** a case whose current status has `isFinal === true`
- **WHEN** the case worker attempts to send an email
- **THEN** the email compose button MUST be disabled
- **AND** a tooltip MUST explain that closed cases cannot send new correspondence

### Requirement: Email templates per case type (zaaktype)
The system MUST support configurable email templates linked to case types, stored as OpenRegister objects under the `emailTemplate` schema.

#### Scenario: Create email template for a case type
- **GIVEN** the case type configuration screen (`CaseTypeDetail.vue`)
- **WHEN** the admin creates a template with name, subject pattern, and HTML body containing `{{variable}}` placeholders
- **THEN** the template MUST be saved as an OpenRegister object with schema `emailTemplate`
- **AND** the template MUST reference the case type ID in its `caseType` field
- **AND** the template MUST appear in the template selector when composing emails on cases of that type

#### Scenario: Template variable resolution with preview
- **GIVEN** a template with body "Beste {{aanvragerNaam}}, uw zaak {{zaakNummer}} is in behandeling genomen op {{startdatum}}."
- **WHEN** the case worker previews the email before sending
- **THEN** all variables MUST be resolved by looking up the case object's fields (title, identifier, startDate, assignee) and linked participant data
- **AND** unresolved variables MUST be highlighted with a red background and a warning banner listing the unresolved variable names

#### Scenario: Available variables sidebar
- **GIVEN** the template editor or email compose view
- **WHEN** the user views the variable reference panel
- **THEN** it MUST list all available variables grouped by source: case fields (identifier, title, startDate, deadline, description), contact fields (name, email, phone, address), and case type fields (title, processingDeadline)
- **AND** clicking a variable name MUST insert it at the cursor position in the editor

#### Scenario: Template versioning
- **GIVEN** an email template that has been used in previously sent emails
- **WHEN** the admin modifies the template text
- **THEN** the system MUST create a new version of the template rather than overwriting
- **AND** previously sent emails MUST retain the template version they were sent with

#### Scenario: Default templates
- **GIVEN** a newly created case type with no custom templates
- **WHEN** the admin views the templates tab
- **THEN** the system MUST offer to create standard templates: "Ontvangstbevestiging" (acknowledgment), "Informatieverzoek" (information request), and "Besluit" (decision notification)

### Requirement: Inbound email linking
The system MUST support linking incoming emails to cases, both automatically via case number detection and manually via a queue interface.

#### Scenario: Auto-link by case number in subject
- **GIVEN** an incoming email with subject "RE: [ZAAK-2026-001234] Uw aanvraag"
- **WHEN** the inbound email handler processes the message
- **THEN** the handler MUST extract the case number using regex pattern `\[([A-Z]+-\d{4}-\d{6})\]`
- **AND** it MUST look up the case by identifier in OpenRegister using `_filters[identifier]=ZAAK-2026-001234`
- **AND** the email MUST be converted to PDF via Docudesk and stored as a `caseDocument`
- **AND** the case activity array MUST receive an entry of type `email_received` with the sender's email address

#### Scenario: Auto-link by Message-ID threading
- **GIVEN** an incoming email whose `In-Reply-To` header matches a previously sent email's `Message-ID`
- **WHEN** the inbound handler processes the message
- **THEN** it MUST look up the original email message object by `messageId` field
- **AND** it MUST link the incoming email to the same case as the original

#### Scenario: Manual email linking via queue
- **GIVEN** an email that could not be auto-linked (no case number in subject, no matching thread)
- **WHEN** the case worker views the unlinked email queue at route `/emails/unlinked`
- **THEN** each unlinked email MUST display sender, subject, date, and body preview
- **AND** the worker MUST be able to search for a case by identifier or title and link the email with one click
- **AND** after linking, the email MUST be removed from the unlinked queue

#### Scenario: Discard unlinked email
- **GIVEN** an unlinked email that is spam or irrelevant
- **WHEN** the case worker selects "Discard" on the email
- **THEN** the email MUST be marked as discarded with a reason (optional)
- **AND** it MUST be moved to a "Discarded" section, not permanently deleted

#### Scenario: Inbound email notification
- **GIVEN** a case with an assigned handler (assignee field)
- **WHEN** a new email is linked to that case (automatically or manually)
- **THEN** the handler MUST receive a Nextcloud notification via `INotificationManager` with a link to the case detail page

### Requirement: Email threading
The system MUST maintain email thread context within cases using RFC 2822 Message-ID and In-Reply-To headers.

#### Scenario: Outbound email creates thread
- **GIVEN** a case with no existing email threads
- **WHEN** the case worker sends the first email
- **THEN** the system MUST generate a unique `Message-ID` header and store it in the `emailMessage` object
- **AND** a new `emailThread` object MUST be created linking the message to the case

#### Scenario: Reply links to existing thread
- **GIVEN** a sent email with `Message-ID: <abc123@procest.example.nl>` on case ZAAK-2026-001234
- **WHEN** a reply arrives with `In-Reply-To: <abc123@procest.example.nl>`
- **THEN** the reply MUST be added to the existing `emailThread` object
- **AND** the thread's `messageCount` field MUST be incremented

#### Scenario: View email thread chronologically
- **GIVEN** a case with a 5-message email thread
- **WHEN** the case worker opens the thread view in the case detail
- **THEN** all messages MUST be displayed in chronological order (oldest first)
- **AND** each message MUST show direction (inbound/outbound), sender, timestamp, subject, and body preview
- **AND** inbound messages MUST have a distinct visual style (e.g., left-aligned) from outbound messages (right-aligned)

#### Scenario: Multiple threads per case
- **GIVEN** a case with two separate email conversations (e.g., one with the applicant, one with an advisor)
- **WHEN** viewing the case's email tab
- **THEN** each thread MUST be displayed as a collapsible group with thread subject as header
- **AND** threads MUST be sorted by most recent message date descending

#### Scenario: Thread subject line consistency
- **GIVEN** an ongoing email thread with subject "[ZAAK-2026-001234] Omgevingsvergunning"
- **WHEN** the case worker replies within the thread
- **THEN** the reply MUST preserve the original subject line with "RE:" prefix
- **AND** the `In-Reply-To` header MUST reference the previous message's `Message-ID`

### Requirement: Email-to-PDF conversion
All emails MUST be converted to PDF for archival as case documents, using Docudesk for PDF generation.

#### Scenario: Convert sent email to PDF
- **GIVEN** a sent email with HTML body and 2 attachments
- **WHEN** the email is stored as a case document
- **THEN** Docudesk MUST generate a PDF that includes email headers (from, to, cc, date, subject) at the top
- **AND** the HTML body MUST be rendered as formatted text in the PDF
- **AND** attachments MUST be listed by filename and size at the end of the PDF (not embedded)

#### Scenario: Convert received email to PDF
- **GIVEN** a received email with plain-text body
- **WHEN** the inbound handler processes the email
- **THEN** the plain text MUST be rendered in the PDF with proper line wrapping
- **AND** any inline images MUST be embedded in the PDF

#### Scenario: PDF stored in case folder
- **GIVEN** a case with identifier ZAAK-2026-001234
- **WHEN** an email PDF is created
- **THEN** the PDF MUST be stored in Nextcloud Files at path `Procest/ZAAK-2026-001234/Correspondentie/{date}_{subject}.pdf`
- **AND** the file MUST be registered as a `caseDocument` object in OpenRegister linking the file path and the case ID

#### Scenario: Conversion failure handling
- **GIVEN** that Docudesk is unavailable or returns an error during PDF conversion
- **WHEN** the system attempts to convert an email
- **THEN** the email message object MUST still be saved in OpenRegister with `pdfStatus: 'failed'`
- **AND** a background job MUST retry the conversion up to 3 times with exponential backoff
- **AND** the case worker MUST see a warning icon on the email indicating PDF conversion pending

#### Scenario: Large email handling
- **GIVEN** an incoming email with body exceeding 5 MB (e.g., large HTML with embedded images)
- **WHEN** the inbound handler processes the email
- **THEN** the email MUST still be processed and linked to the case
- **AND** the PDF conversion MUST be delegated to a background job rather than processed synchronously

### Requirement: Email compose UI component
The case detail view MUST include an email composition interface accessible from the case detail page.

#### Scenario: Open email composer from case detail
- **GIVEN** the case detail view (`CaseDetail.vue`) with a non-final status
- **WHEN** the case worker clicks "Send email" in the case actions
- **THEN** a modal dialog MUST open with fields for: recipient (pre-filled from case contact), CC, BCC, subject (pre-filled with case identifier prefix), body (rich text editor), template selector, and attachment picker

#### Scenario: Rich text editor for email body
- **GIVEN** the email compose dialog is open
- **WHEN** the case worker types in the body field
- **THEN** the editor MUST support bold, italic, links, bulleted lists, and numbered lists
- **AND** the editor MUST use the Nextcloud text editor component or a compatible WYSIWYG

#### Scenario: Attachment picker from case documents
- **GIVEN** the email compose dialog is open
- **WHEN** the case worker clicks "Attach document"
- **THEN** a document picker MUST display the case's existing documents (fetched from `caseDocument` objects)
- **AND** the worker MUST be able to select multiple documents
- **AND** the running total attachment size MUST be displayed below the attachment list

#### Scenario: Template selector pre-fills body and subject
- **GIVEN** the email compose dialog is open and the case has a case type with configured templates
- **WHEN** the case worker selects a template from the dropdown
- **THEN** the subject and body fields MUST be pre-filled with the template's content
- **AND** template variables MUST be resolved immediately with case data
- **AND** the worker MUST be able to edit the pre-filled content before sending

#### Scenario: Send confirmation
- **GIVEN** the email compose form is filled out
- **WHEN** the case worker clicks "Send"
- **THEN** a confirmation dialog MUST appear showing recipient count and attachment count
- **AND** after confirmation, the email MUST be sent and the compose dialog MUST close
- **AND** the case activity timeline MUST refresh to show the new email event

### Requirement: Inbound email polling background job
The system MUST poll configured IMAP mailboxes for new emails using Nextcloud's `IJobList` background job infrastructure.

#### Scenario: Register background job on app enable
- **GIVEN** the Procest app is enabled and IMAP settings are configured
- **WHEN** the app registers its background jobs
- **THEN** an `InboundEmailJob` MUST be registered with `IJobList` as a `TimedJob` with configurable interval (default: 5 minutes, stored in `IAppConfig` key `email_poll_interval`)

#### Scenario: Poll IMAP mailbox for new messages
- **GIVEN** the background job runs
- **WHEN** it connects to the configured IMAP server
- **THEN** it MUST fetch all unread messages from the configured folder (default: INBOX)
- **AND** for each message, it MUST attempt auto-linking by subject and thread headers
- **AND** successfully processed messages MUST be moved to a "Processed" IMAP folder

#### Scenario: IMAP connection failure
- **GIVEN** the IMAP server is unreachable
- **WHEN** the background job attempts to connect
- **THEN** it MUST log the failure via `LoggerInterface` at error level
- **AND** it MUST NOT throw an exception that would deregister the job
- **AND** the next scheduled run MUST proceed normally

#### Scenario: Rate limiting
- **GIVEN** a large mailbox with 500 unread messages
- **WHEN** the background job processes messages
- **THEN** it MUST process at most 50 messages per run (configurable via `email_poll_batch_size`)
- **AND** remaining messages MUST be picked up in subsequent runs

#### Scenario: Duplicate detection
- **GIVEN** an email that has already been processed (its `Message-ID` exists in the `emailMessage` objects)
- **WHEN** the background job encounters the same email again (e.g., not moved due to IMAP error)
- **THEN** it MUST skip the duplicate and mark it as processed
- **AND** it MUST NOT create a duplicate case document

### Requirement: SMTP and IMAP configuration
The admin settings MUST provide configuration for outbound SMTP and inbound IMAP server settings.

#### Scenario: Configure SMTP settings
- **GIVEN** the Procest admin settings page (`Settings.vue` or dedicated email tab)
- **WHEN** the admin enters SMTP host, port, encryption (none/STARTTLS/SSL), username, password, and from-address
- **THEN** the settings MUST be stored in `IAppConfig` under keys prefixed with `email_smtp_`
- **AND** the password MUST be stored encrypted using `ISecureRandom` or Nextcloud's credential store

#### Scenario: Test SMTP connection
- **GIVEN** SMTP settings are configured
- **WHEN** the admin clicks "Send test email"
- **THEN** the system MUST attempt to send a test email to the admin's email address
- **AND** on success, a green "Connection successful" message MUST appear
- **AND** on failure, the specific error message MUST be displayed (e.g., "Authentication failed", "Connection refused")

#### Scenario: Configure IMAP mailbox
- **GIVEN** the admin settings
- **WHEN** the admin enters IMAP host, port, encryption, username, password, and folder name
- **THEN** the settings MUST be stored in `IAppConfig` under keys prefixed with `email_imap_`
- **AND** the system MUST validate the connection immediately and display the result

#### Scenario: Use Nextcloud Mail app as transport
- **GIVEN** the Nextcloud Mail app is installed and the admin has configured a Mail account
- **WHEN** the admin selects "Use Nextcloud Mail" in the email transport configuration
- **THEN** outbound emails MUST be sent through the Mail app's SMTP infrastructure
- **AND** the admin MUST select which Mail account to use from a dropdown

#### Scenario: Configuration validation on save
- **GIVEN** the admin enters email configuration
- **WHEN** the admin clicks "Save"
- **THEN** the system MUST validate that all required fields are filled (host, port, from-address for SMTP)
- **AND** if validation fails, the specific missing fields MUST be highlighted with error messages

### Requirement: Email OpenRegister schemas
The system MUST define OpenRegister schemas for email templates, messages, and threads in the `procest_register.json` configuration.

#### Scenario: emailTemplate schema definition
- **GIVEN** the register configuration at `lib/Settings/procest_register.json`
- **WHEN** the register is imported via `ConfigurationService::importFromApp()`
- **THEN** an `emailTemplate` schema MUST be created with properties: name (string, required), subject (string, required), body (string/HTML, required), caseType (string/reference, required), variables (array of available variable names), version (integer, default 1), isActive (boolean, default true)

#### Scenario: emailMessage schema definition
- **GIVEN** the register configuration
- **WHEN** the register is imported
- **THEN** an `emailMessage` schema MUST be created with properties: messageId (string, RFC 2822 Message-ID), inReplyTo (string, optional), direction (enum: inbound/outbound), from (string), to (array of strings), cc (array of strings), bcc (array of strings), subject (string), body (string/HTML), case (string/reference to case), thread (string/reference to emailThread), pdfPath (string), pdfStatus (enum: pending/completed/failed), sentAt (datetime), templateId (string, optional reference to emailTemplate), templateVersion (integer, optional)

#### Scenario: emailThread schema definition
- **GIVEN** the register configuration
- **WHEN** the register is imported
- **THEN** an `emailThread` schema MUST be created with properties: subject (string), case (string/reference to case), messageCount (integer), firstMessageAt (datetime), lastMessageAt (datetime)

#### Scenario: Schema auto-configuration
- **GIVEN** the schemas are imported
- **WHEN** `SettingsService::autoConfigureAfterImport()` runs
- **THEN** the schema IDs for `emailTemplate`, `emailMessage`, and `emailThread` MUST be stored in `IAppConfig` under keys `email_template_schema`, `email_message_schema`, `email_thread_schema`
- **AND** the object store MUST register these types via `registerObjectType()` during `initializeStores()`

#### Scenario: Schema.org type annotations
- **GIVEN** the email schemas in `procest_register.json`
- **WHEN** the schemas are defined
- **THEN** `emailTemplate` MUST include Schema.org annotation `schema:DigitalDocument`
- **AND** `emailMessage` MUST include annotation `schema:EmailMessage`
- **AND** `emailThread` MUST include annotation `schema:Conversation`

### Requirement: Email tab in case detail view
The case detail view MUST include a dedicated email tab showing all email correspondence for the case.

#### Scenario: Email tab displays message list
- **GIVEN** a case with 8 emails across 3 threads
- **WHEN** the case worker clicks the "Email" tab in the case detail view
- **THEN** the tab MUST display all emails grouped by thread
- **AND** each thread group MUST show the thread subject, message count, and date of last message
- **AND** the most recent thread MUST appear at the top

#### Scenario: Empty state for cases with no emails
- **GIVEN** a case with no email correspondence
- **WHEN** the case worker views the email tab
- **THEN** an empty state MUST be shown with text "No email correspondence yet"
- **AND** a "Send email" button MUST be prominently displayed

#### Scenario: Email count badge in tab header
- **GIVEN** a case with 5 emails
- **WHEN** the case detail tabs render
- **THEN** the Email tab MUST display a count badge showing "5"

#### Scenario: Inline email view
- **GIVEN** the email tab with message list
- **WHEN** the case worker clicks on an email message
- **THEN** the full email body MUST be displayed inline (expanding the message row)
- **AND** the PDF download link MUST be available next to the message

#### Scenario: Reply from email tab
- **GIVEN** the email tab showing a received email
- **WHEN** the case worker clicks "Reply" on a specific message
- **THEN** the email compose dialog MUST open with the recipient pre-filled from the original sender
- **AND** the subject MUST be prefixed with "RE:"
- **AND** the original message body MUST be quoted below the compose area

### Requirement: Accessibility and internationalization
The email integration MUST meet WCAG AA compliance and support both English and Dutch.

#### Scenario: Keyboard navigation in email compose
- **GIVEN** the email compose dialog is open
- **WHEN** the user navigates using only the keyboard
- **THEN** all form fields, buttons, and the template selector MUST be reachable via Tab key
- **AND** the send button MUST be activatable via Enter key
- **AND** Escape MUST close the dialog

#### Scenario: Screen reader support for email list
- **GIVEN** the email tab in case detail
- **WHEN** a screen reader reads the email list
- **THEN** each email MUST have an ARIA label including direction (sent/received), sender, date, and subject
- **AND** thread groups MUST use ARIA role "group" with a label

#### Scenario: Dutch language support
- **GIVEN** a user with Dutch locale
- **WHEN** viewing the email integration UI
- **THEN** all labels, buttons, error messages, and empty states MUST be displayed in Dutch
- **AND** default template names MUST be in Dutch (e.g., "Ontvangstbevestiging", "Informatieverzoek")

### Requirement: Email audit trail integration
All email events MUST be recorded in the case activity timeline for compliance and audit purposes.

#### Scenario: Sent email appears in activity timeline
- **GIVEN** a case with the activity timeline component (`ActivityTimeline.vue`)
- **WHEN** an email is sent from the case
- **THEN** the activity array MUST include an entry with type `email_sent`, the template name (if used), recipient list, and timestamp
- **AND** the timeline MUST display an email icon for email events

#### Scenario: Received email appears in activity timeline
- **GIVEN** an incoming email is linked to a case (auto or manual)
- **WHEN** the case detail loads
- **THEN** the activity array MUST include an entry with type `email_received`, sender email, subject line, and timestamp

#### Scenario: Email events in ZGW audit trail
- **GIVEN** ZGW mapping is configured for the case type
- **WHEN** an email event occurs
- **THEN** the event MUST be mappable to a ZGW AuditTrail entry via `ZgwMappingService`
- **AND** the informatieobject (PDF document) MUST be linkable via `zaakInformatieobject`

## Dependencies
- Nextcloud Mail app (optional, for using existing Mail accounts as transport)
- Docudesk for email-to-PDF conversion
- OpenRegister for case data, email template storage, and message storage
- Nextcloud IJobList for background job scheduling (inbound polling)
- Nextcloud INotificationManager for new email notifications
- Nextcloud IRootFolder for file storage of email PDFs

## Current Implementation Status

**Not yet implemented.** No email-related services, controllers, or Vue components exist in the Procest codebase. There are no email template schemas, SMTP/IMAP configuration fields, or email-to-PDF conversion logic.

**Foundation available:**
- The `NotificatieService` (`lib/Service/NotificatieService.php`) handles ZGW notification channels (kanaal/abonnement schemas exist in config), which could be extended for email notifications.
- Document schemas exist (`document_schema`, `caseDocument` in `SettingsService::SLUG_TO_CONFIG_KEY`) for storing sent/received emails as case documents.
- Activity timeline component (`src/views/cases/components/ActivityTimeline.vue`) would display email events.
- Docudesk (external dependency) provides PDF generation capabilities for email-to-PDF conversion.
- OpenConnector could host SMTP/IMAP adapters.
- `CaseDetail.vue` already has the card-based layout pattern where an email tab/card could be added.
- `IAppConfig` is already used in `SettingsService` for all app configuration keys.

**Partial implementations:** None.

## Standards & References

- **SMTP/IMAP**: Standard email protocols for sending and receiving.
- **RFC 2822**: Message-ID and In-Reply-To header format for email threading.
- **Nextcloud Mail App**: Potential integration point for email composition and mailbox management.
- **ZGW Documenten API (VNG)**: Sent/received emails stored as informatieobjecten follow ZGW DRC patterns.
- **Archiefwet / NEN 2082**: Email archival as PDF follows Dutch archiving standards for government correspondence.
- **AVG/GDPR**: Email content containing citizen data must be handled per privacy regulations.
- **WCAG AA**: Email composer and template editor must be accessible.
- **Schema.org**: EmailMessage, DigitalDocument, Conversation type annotations.
- **CMMN 1.1**: Email events as case file items within the case plan model.

## Specificity Assessment

This spec is highly detailed with 12 requirements and comprehensive scenarios covering the full email lifecycle.

**Key design decisions made:**
- Email templates, messages, and threads are stored as OpenRegister objects (not in separate tables).
- PDF conversion uses Docudesk, with background job retry for failures.
- Threading uses standard RFC 2822 headers (Message-ID, In-Reply-To).
- Case number extraction uses regex pattern `\[([A-Z]+-\d{4}-\d{6})\]`.
- IMAP polling is a Nextcloud `TimedJob` with configurable interval and batch size.
- Email compose UI is a modal dialog accessible from the case detail view.
- Both Nextcloud Mail app integration and standalone SMTP/IMAP are supported.

**Feature tier (FEATURES.md):** V1 (not MVP).
