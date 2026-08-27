---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# complaint-management Specification

## Purpose
Manages citizen complaints as first-class entities with their own schema, sequential numbering, and the Awb chapter 9 lifecycle with enforced deadlines and extension (verdaging). Supports multi-channel intake, hearings (hoorgesprek) including calendar and video integration, escalation to formal cases, disposition tracking, complainant communication, and configurable per-tenant categories. Provides list, detail, and dashboard views plus frequency analysis that flags recurring patterns and systemic issues for management attention.
## Requirements
### Requirement: Complaints MUST be first-class entities with dedicated schema
The system SHALL treat complaints as first-class entities with their own OpenRegister schema and lifecycle, distinct from a regular zaak but sharing the case infrastructure.

#### Scenario: Register a new complaint via intake form
- **GIVEN** the Dossiq complaints module is enabled
- **WHEN** a case worker registers a complaint received from a citizen
- **THEN** a complaint object MUST be created in OpenRegister with:
  - `klachtnummer`: auto-generated (format: `KL-{year}-{sequence}`, e.g., `KL-2026-0042`)
  - `klager`: reference to the person filing the complaint (name, email, phone, BSN if known)
  - `onderwerp`: subject of the complaint (short title)
  - `omschrijving`: detailed description of the complaint
  - `ontvangstdatum`: date the complaint was received
  - `ontvangstkanaal`: intake channel enum (`balie`, `telefoon`, `email`, `brief`, `website`, `socialmedia`)
  - `categorie`: complaint category (configurable per tenant)
  - `betrokkenMedewerker`: optional reference to the employee the complaint is about
  - `betrokkenAfdeling`: optional reference to the department
  - `status`: initial status `ontvangen`
  - `behandelaar`: assigned complaint handler
  - `prioriteit`: priority level (`laag`, `normaal`, `hoog`, `urgent`)

#### Scenario: Complaint numbering is sequential per year
- **GIVEN** 41 complaints have been registered in 2026
- **WHEN** a new complaint is created on 2026-03-20
- **THEN** the complaint number MUST be `KL-2026-0042`
- **AND** the sequence MUST reset to 0001 on January 1, 2027

#### Scenario: Complaint intake from multiple channels
- **GIVEN** a complaint arrives via email to klachten@gemeente.nl
- **WHEN** the n8n email trigger processes the incoming email
- **THEN** a complaint object MUST be auto-created with `ontvangstkanaal` set to `email`
- **AND** the email body MUST be stored as `omschrijving`
- **AND** the sender's email MUST be stored in `klager.email`
- **AND** the complaint handler MUST receive a notification to review and complete the intake

#### Scenario: Complaint data validation
- **GIVEN** a case worker is creating a new complaint
- **WHEN** they attempt to save without filling required fields (`onderwerp`, `omschrijving`, `ontvangstdatum`)
- **THEN** the system MUST display validation errors for each missing required field
- **AND** the complaint MUST NOT be saved until validation passes

### Requirement: Complaints MUST follow the Awb chapter 9 lifecycle with enforced deadlines
The Awb prescribes specific complaint handling timelines that the system MUST calculate and enforce.

#### Scenario: Awb deadline calculation on complaint creation
- **GIVEN** complaint `KL-2026-0042` is received on 2026-03-01 (Monday)
- **WHEN** the complaint is created
- **THEN** the system MUST automatically calculate:
  - `ontvangstbevestigingDeadline`: 5 working days = 2026-03-08 (following Monday, skipping weekend)
  - `afhandelDeadline`: 6 weeks = 2026-04-12
  - `verdagingMogelijk`: true (4-week extension available, extending to 2026-05-10)
- **AND** these deadlines MUST be stored on the complaint object

#### Scenario: Complaint lifecycle status transitions
- **GIVEN** complaint `KL-2026-0042` with status `ontvangen`
- **THEN** the following status transitions MUST be enforced:
  - `ontvangen` -> `ontvangst_bevestigd` (acknowledgment sent)
  - `ontvangst_bevestigd` -> `in_behandeling` (investigation started)
  - `in_behandeling` -> `hoorgesprek_gepland` (hearing scheduled)
  - `hoorgesprek_gepland` -> `hoorgesprek_afgerond` (hearing completed)
  - `hoorgesprek_afgerond` -> `afgehandeld` (resolution with disposition)
  - Any status -> `ingetrokken` (complainant withdraws)
- **AND** skipping the hearing stages MUST be allowed when the complainant waives the right to be heard

#### Scenario: Acknowledgment deadline warning at 3 days
- **GIVEN** complaint `KL-2026-0042` received on 2026-03-01 with `ontvangstbevestigingDeadline` 2026-03-08
- **AND** the current date is 2026-03-05 (3 working days elapsed)
- **AND** status is still `ontvangen` (no acknowledgment sent)
- **THEN** the system MUST send a warning notification to the complaint handler
- **AND** the complaint MUST appear in the "Dreigend verlopen" section of the complaints dashboard

#### Scenario: Resolution deadline warning and escalation
- **GIVEN** complaint `KL-2026-0042` has `afhandelDeadline` 2026-04-12
- **AND** the current date is 2026-04-05 (1 week before deadline)
- **AND** status is `in_behandeling`
- **THEN** the system MUST send a warning to the handler and their coordinator
- **AND** if the deadline passes without resolution, the complaint MUST be flagged as "Verlopen"
- **AND** the coordinator MUST receive an escalation notification

#### Scenario: Request deadline extension (verdaging)
- **GIVEN** complaint `KL-2026-0042` has `afhandelDeadline` 2026-04-12 and `verdagingMogelijk` is true
- **WHEN** the handler requests a 4-week extension with written justification
- **THEN** `afhandelDeadline` MUST be updated to 2026-05-10
- **AND** `verdagingMogelijk` MUST be set to false (only one extension allowed per Awb)
- **AND** the complainant MUST be notified of the extension with the justification
- **AND** the extension MUST be recorded in the audit trail

### Requirement: Complaints MUST support a hearing (hoorgesprek)
The system SHALL support a hearing (hoorgesprek) process, as the Awb gives the complainant the right to be heard before a decision is made on the complaint.

#### Scenario: Schedule a hearing
- **GIVEN** complaint `KL-2026-0042` is `in_behandeling`
- **WHEN** the handler schedules a hearing
- **THEN** a hearing record MUST be created as a linked object with:
  - `datum`: scheduled date and time
  - `locatie`: location (physical address or video conferencing link)
  - `deelnemers`: list of participants (klager, behandelaar, betrokken medewerker, optional witnesses)
  - `type`: hearing type (`fysiek`, `telefonisch`, `videogesprek`)
- **AND** the complaint status MUST change to `hoorgesprek_gepland`
- **AND** calendar invitations MUST be sent to all participants via Nextcloud Calendar (`OCP\Calendar\IManager`)

#### Scenario: Record hearing outcome
- **GIVEN** the hearing for `KL-2026-0042` has taken place
- **WHEN** the handler records the outcome
- **THEN** the hearing record MUST be updated with:
  - `verslag`: summary of the hearing (mandatory)
  - `conclusie`: preliminary conclusion
  - `aanwezigen`: actual attendees (may differ from planned participants)
  - `datumAfgerond`: actual hearing date
- **AND** the complaint status MUST change to `hoorgesprek_afgerond`

#### Scenario: Complainant waives right to hearing
- **GIVEN** complaint `KL-2026-0042` is `in_behandeling`
- **WHEN** the complainant explicitly waives their right to be heard
- **THEN** the handler MUST record the waiver with: waiver date, method (email/brief/telefoon), and confirmation text
- **AND** the complaint MUST skip the hearing stages and proceed directly to disposition
- **AND** the waiver MUST be stored as a document attached to the complaint

#### Scenario: Hearing with video conferencing integration
- **GIVEN** the hearing type is `videogesprek`
- **WHEN** the hearing is scheduled
- **THEN** the system MUST create a Talk conversation (via `OCP\Talk\IBroker`) and attach the link to the hearing record
- **AND** the video link MUST be included in the calendar invitation

### Requirement: Complaints MUST support escalation to formal cases
The system SHALL support escalation of a complaint to a formal case (zaak) when a complaint reveals a larger issue, while maintaining the bidirectional link.

#### Scenario: Escalate complaint to formal case
- **GIVEN** complaint `KL-2026-0042` reveals a systemic service failure in the building permits department
- **WHEN** the handler clicks "Escaleren naar zaak" and selects zaaktype "Intern onderzoek"
- **THEN** a new zaak MUST be created in Dossiq with the selected zaaktype
- **AND** the zaak MUST reference the originating complaint (`bronKlacht`: complaint ID)
- **AND** the complaint MUST reference the created zaak (`geescaleerdeZaak`: case ID)
- **AND** the complaint's documents and hearing records MUST be accessible from the zaak
- **AND** the complaint status MUST remain independently trackable (not closed by escalation)

#### Scenario: View escalated case from complaint
- **GIVEN** complaint `KL-2026-0042` has been escalated to case "ZAAK-2026-000567"
- **WHEN** viewing the complaint detail
- **THEN** a "Gerelateerde zaak" section MUST show the linked case with: case number, status, and a link to the case detail
- **AND** updates to the case MUST be visible in the complaint's activity timeline

#### Scenario: Multiple complaints escalate to same case
- **GIVEN** 3 complaints about the same department issue are received
- **WHEN** the handler escalates all 3 to the same case
- **THEN** the case MUST reference all 3 complaints
- **AND** each complaint MUST reference the case
- **AND** the case detail MUST show all linked complaints

### Requirement: Disposition tracking MUST record how complaints are resolved
The system SHALL record how complaints are resolved through a formal disposition (oordeel) that classifies the outcome.

#### Scenario: Close complaint with disposition
- **GIVEN** complaint `KL-2026-0042` has been investigated and the hearing is completed
- **WHEN** the handler closes the complaint
- **THEN** a disposition MUST be recorded with:
  - `oordeel`: enum (`gegrond`, `deels_gegrond`, `ongegrond`, `ingetrokken`, `niet_ontvankelijk`)
  - `toelichting`: explanation of the judgment (mandatory for `gegrond` and `deels_gegrond`)
  - `maatregelen`: actions taken or promised (structured list with description and responsible party)
  - `afsluitdatum`: date of closure
  - `afsluitbrief`: reference to the formal response letter document
- **AND** the complaint status MUST change to `afgehandeld`

#### Scenario: Disposition requires coordinator approval
- **GIVEN** the tenant is configured to require approval for complaint dispositions
- **WHEN** the handler submits a disposition with oordeel `gegrond`
- **THEN** the disposition MUST enter `wacht_op_goedkeuring` state
- **AND** the coordinator MUST receive a task to review and approve or reject the disposition
- **AND** the complaint deadline timer MUST continue running during approval

#### Scenario: Generate formal response letter
- **GIVEN** complaint `KL-2026-0042` has disposition `deels_gegrond` with maatregelen
- **WHEN** the handler clicks "Afsluitbrief genereren"
- **THEN** the system MUST generate a response letter using the complaint template (via Docudesk integration)
- **AND** the letter MUST include: complaint number, subject, disposition, explanation, and proposed measures
- **AND** the letter MUST be stored as a document linked to the complaint

#### Scenario: Disposition statistics
- **GIVEN** 100 complaints were closed in Q1 2026
- **WHEN** a manager views the disposition report
- **THEN** the system MUST show: gegrond (15%), deels_gegrond (25%), ongegrond (45%), ingetrokken (10%), niet_ontvankelijk (5%)
- **AND** the percentages MUST be broken down by category and department

### Requirement: Frequency analysis MUST detect patterns in complaints
The system SHALL detect patterns in complaints, as recurring complaints about the same subject, department, or employee signal systemic issues that require management attention.

#### Scenario: Complaint frequency dashboard
- **GIVEN** 5 complaints in the last quarter are about waiting times at the balie
- **WHEN** a manager views the complaint analytics dashboard
- **THEN** the system MUST show:
  - Complaint frequency by category (bar chart)
  - Complaint frequency by department (bar chart)
  - Complaint frequency by intake channel
  - Trend over time (line chart, monthly granularity)
  - Average resolution time by category
- **AND** categories with significantly increased frequency (>50% increase vs. previous quarter) MUST be flagged

#### Scenario: Employee complaint threshold alert
- **GIVEN** 3 complaints in the last 6 months reference the same `betrokkenMedewerker`
- **WHEN** the threshold of 3 complaints per employee per 6 months is exceeded
- **THEN** the system MUST alert the HR coordinator and the department head
- **AND** the alert MUST include: employee reference (anonymized in the notification), complaint count, categories, and periods
- **AND** the alert MUST NOT be visible to the regular complaint handlers (privacy protection)

#### Scenario: Systemic issue detection
- **GIVEN** complaint categories `wachttijd_balie` and `telefonische_bereikbaarheid` both show >100% increase in Q1 2026
- **WHEN** the quarterly analysis runs
- **THEN** the system MUST generate a "Systeemmelding" with: affected categories, complaint counts, trend direction, and suggested action
- **AND** the systemic issue report MUST be exportable as PDF for management reporting

#### Scenario: Benchmarking against targets
- **GIVEN** the municipality has set targets: max 10 complaints/month, >90% resolved within Awb deadline, <15% gegrond rate
- **WHEN** the dashboard loads
- **THEN** KPI cards MUST show actual vs. target for each metric
- **AND** metrics exceeding targets MUST be highlighted in red

### Requirement: Complaint categories MUST be configurable per tenant
The system SHALL support configurable complaint categories per tenant, allowing each municipality to define its own categories to match their organizational structure.

#### Scenario: Configure complaint categories
- **GIVEN** a tenant admin accesses Settings > Klachtcategorieen
- **WHEN** they define categories
- **THEN** they MUST be able to create, edit, and deactivate categories with: name, description, default handler (user or group), and SLA override (custom deadline)
- **AND** default categories MUST be pre-configured: "Dienstverlening", "Bejegening", "Wachttijd", "Informatievoorziening", "Procedures"

#### Scenario: Category-specific routing
- **GIVEN** category "Bejegening" has default handler set to group "HR-Klachten"
- **WHEN** a complaint is created with category "Bejegening"
- **THEN** the complaint MUST be automatically assigned to the "HR-Klachten" group
- **AND** a member of the group MUST be able to claim the complaint

#### Scenario: Deactivate category without data loss
- **GIVEN** category "Legacy categorie" has 15 historical complaints
- **WHEN** the admin deactivates the category
- **THEN** new complaints MUST NOT be assignable to this category
- **AND** existing complaints with this category MUST retain their category value
- **AND** the category MUST still appear in historical reports

### Requirement: Complaint views MUST integrate with the Dossiq dashboard
Complaints MUST be accessible through dedicated views and dashboard widgets.

#### Scenario: Complaint list view
- **GIVEN** the complaints module is enabled
- **WHEN** a complaint handler navigates to "Klachten" in the sidebar
- **THEN** a list view MUST show all complaints assigned to them with: complaint number, subject, category, status, received date, deadline, and days remaining
- **AND** overdue complaints MUST be sorted to the top and highlighted in red
- **AND** the list MUST support filtering by: status, category, handler, date range, and priority

#### Scenario: Complaint detail view
- **GIVEN** complaint `KL-2026-0042` exists
- **WHEN** the handler clicks on it in the complaint list
- **THEN** a detail view MUST show: all complaint fields, status timeline, deadline panel (reusing DeadlinePanel.vue), hearing records, linked documents, activity timeline, and linked case (if escalated)
- **AND** the handler MUST be able to change status, schedule hearing, record disposition, and escalate to case from this view

#### Scenario: Dashboard complaint widget
- **GIVEN** the Dossiq dashboard (Dashboard.vue)
- **WHEN** a complaint handler views their dashboard
- **THEN** a "Mijn klachten" widget MUST show: open complaints count, overdue count, and upcoming deadlines (next 5 working days)
- **AND** clicking the widget MUST navigate to the filtered complaint list

#### Scenario: Complaint KPI cards on management dashboard
- **GIVEN** a coordinator or manager views the dashboard
- **THEN** complaint KPI cards MUST show: total complaints this month, average resolution time, Awb compliance rate (% resolved within deadline), and disposition breakdown (gegrond/ongegrond pie chart)

### Requirement: Complainant communication MUST be tracked
All communication with the complainant MUST be recorded as part of the complaint record.

#### Scenario: Send acknowledgment letter
- **GIVEN** complaint `KL-2026-0042` is in status `ontvangen`
- **WHEN** the handler clicks "Ontvangstbevestiging verzenden"
- **THEN** a template letter MUST be generated (via Docudesk) with: complaint number, received date, handler name, and expected resolution date
- **AND** the letter MUST be sent via the configured channel (email or print queue)
- **AND** the complaint status MUST change to `ontvangst_bevestigd`
- **AND** the sent letter MUST be stored as a document linked to the complaint

#### Scenario: Track phone call with complainant
- **GIVEN** the handler makes a phone call to the complainant
- **WHEN** they record the call in the complaint
- **THEN** a communication record MUST be created with: date, duration, summary, and follow-up actions
- **AND** the communication MUST appear in the complaint's activity timeline

#### Scenario: Complainant submits additional information
- **GIVEN** complaint `KL-2026-0042` is `in_behandeling`
- **WHEN** the complainant sends additional documents via email
- **THEN** the n8n email handler MUST link the attachments to the existing complaint (matching on complaint number in subject line)
- **AND** the handler MUST receive a notification about the new attachments

