# case-email-integration Specification

## Purpose
Send and receive email from within case context. Emails are converted to PDF and stored as case documents, creating a complete communication audit trail. Template variables from case data enable consistent correspondence.

## Context
Email remains a primary communication channel between municipalities and citizens/organizations. Currently, email communication happens outside the case system, making it impossible to reconstruct the full communication history. This spec integrates email directly into the case workflow: outbound emails use templates with case data, and all sent/received emails are archived as case documents.

## ADDED Requirements

### Requirement: Send email from case context
The system MUST support sending email from within a case, with the email stored as a case document.

#### Scenario: Send email with case template
- GIVEN a case of type "Omgevingsvergunning" with configured email templates
- WHEN the case worker selects template "Ontvangstbevestiging" and clicks send
- THEN template variables ({{zaakNummer}}, {{aanvragerNaam}}, {{startdatum}}) MUST be resolved from case data
- AND the email MUST be sent to the case's primary contact email address
- AND a PDF copy of the sent email MUST be created and linked as a case document
- AND the case timeline MUST show "Email verzonden: Ontvangstbevestiging"

#### Scenario: Send ad-hoc email without template
- GIVEN a case with a linked contact email
- WHEN the case worker composes a free-form email with subject and body
- THEN the email MUST be sent with the municipality's configured from address
- AND the case number MUST be included in the email subject (e.g., "[ZAAK-2026-001234] Uw aanvraag")
- AND the sent email MUST be stored as a case document

#### Scenario: Send email with attachments
- GIVEN a case with existing documents
- WHEN the case worker attaches case documents to the email
- THEN the selected documents MUST be included as email attachments
- AND the total attachment size MUST NOT exceed the configured limit (default: 25 MB)

### Requirement: Email templates per zaaktype
The system MUST support configurable email templates linked to zaaktypes.

#### Scenario: Configure email template
- GIVEN the zaaktype configuration screen
- WHEN the admin creates a template with name, subject pattern, and body with variables
- THEN the template MUST be available when sending email from cases of that type
- AND available variables MUST be listed in a sidebar (case fields, contact fields, dates)

#### Scenario: Template variable resolution
- GIVEN a template with body "Beste {{aanvragerNaam}}, uw zaak {{zaakNummer}} is in behandeling genomen op {{startdatum}}."
- WHEN the case worker previews the email
- THEN all variables MUST be replaced with actual case data
- AND unresolved variables MUST be highlighted in red with a warning

### Requirement: Inbound email linking
The system MUST support linking incoming emails to cases.

#### Scenario: Auto-link by case number in subject
- GIVEN an incoming email with subject "RE: [ZAAK-2026-001234] Uw aanvraag"
- WHEN the email is processed by the inbound handler
- THEN the email MUST be automatically linked to case ZAAK-2026-001234
- AND the email MUST be converted to PDF and stored as a case document
- AND the case timeline MUST show "Email ontvangen van: burger@example.nl"

#### Scenario: Manual email linking
- GIVEN an email that could not be auto-linked (no case number in subject)
- WHEN the case worker views the unlinked email queue
- THEN they MUST be able to search for a case and link the email manually

### Requirement: Email threading
The system MUST maintain email thread context within cases.

#### Scenario: Reply creates thread
- GIVEN a sent email "Ontvangstbevestiging" on case ZAAK-2026-001234
- WHEN the citizen replies to that email
- AND the reply is processed by the inbound handler
- THEN the reply MUST be linked to the same thread as the original message
- AND the case timeline MUST show the thread as a grouped conversation

#### Scenario: View email thread
- GIVEN a case with a 5-message email thread
- WHEN the case worker opens the thread view
- THEN all messages MUST be displayed in chronological order
- AND each message MUST show sender, timestamp, subject, and body preview

### Requirement: Email-to-PDF conversion
All emails MUST be converted to PDF for archival as case documents.

#### Scenario: Convert sent email to PDF
- GIVEN a sent email with HTML body and 2 attachments
- WHEN the email is stored as a case document
- THEN the PDF MUST include email headers (from, to, date, subject)
- AND the body MUST be rendered as formatted text
- AND attachments MUST be listed by name (not embedded in the PDF)

## Admin Configuration

#### Scenario: Configure SMTP settings
- GIVEN the Procest admin settings
- WHEN the admin configures SMTP server, port, authentication, and from address
- THEN outbound emails MUST use these settings
- AND a "Send test email" button MUST verify the configuration

#### Scenario: Configure inbound mailbox
- GIVEN the admin settings
- WHEN the admin configures an IMAP mailbox for inbound email processing
- THEN a background job MUST poll the mailbox at configurable intervals (default: 5 minutes)
- AND processed emails MUST be moved to a "Processed" folder

## Dependencies
- Nextcloud Mail app or direct SMTP/IMAP integration
- Docudesk for email-to-PDF conversion
- OpenRegister for case data (template variable resolution)
- Background jobs for inbound email polling

### Current Implementation Status

**Not yet implemented.** No email-related services, controllers, or Vue components exist in the Procest codebase. There are no email template schemas, SMTP/IMAP configuration fields, or email-to-PDF conversion logic.

**Foundation available:**
- The `NotificatieService` (`lib/Service/NotificatieService.php`) handles ZGW notification channels (kanaal/abonnement schemas exist in config), which could be extended for email notifications.
- Document schemas exist (`document_schema`, `caseDocument` in `SettingsService::SLUG_TO_CONFIG_KEY`) for storing sent/received emails as case documents.
- Activity timeline component (`src/views/cases/components/ActivityTimeline.vue`) would display email events.
- Docudesk (external dependency) provides PDF generation capabilities for email-to-PDF conversion.
- OpenConnector could host SMTP/IMAP adapters.

**Partial implementations:** None.

### Standards & References

- **SMTP/IMAP**: Standard email protocols for sending and receiving.
- **Nextcloud Mail App**: Potential integration point for email composition and mailbox management.
- **ZGW Documenten API (VNG)**: Sent/received emails stored as informatieobjecten follow ZGW DRC patterns.
- **Archiefwet / NEN 2082**: Email archival as PDF follows Dutch archiving standards for government correspondence.
- **AVG/GDPR**: Email content containing citizen data must be handled per privacy regulations.
- **WCAG AA**: Email composer and template editor must be accessible.

### Specificity Assessment

This spec is moderately specific -- it covers the key user stories but lacks technical depth.

**What's missing:**
- No OpenRegister schema for email templates (fields, variable syntax, zaaktype linkage).
- No specification of the email composer UI component.
- No specification of the IMAP polling background job implementation (Nextcloud `IJobList`).
- No specification of email thread data model (Message-ID, In-Reply-To headers).
- No specification of how the case number is extracted from email subjects (regex pattern, error handling).
- No specification of the unlinked email queue UI.
- Variable syntax `{{variable}}` is shown but not formally defined (available variables, nested access, formatting).

**Open questions:**
1. Should email integration use Nextcloud Mail app's infrastructure or implement direct SMTP/IMAP?
2. How are email templates versioned when a zaaktype is updated?
3. Should the system support rich-text email or plain text only?
4. How is the email-to-PDF conversion triggered -- synchronously on receipt or via background job?
