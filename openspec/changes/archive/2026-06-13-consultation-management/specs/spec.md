---
status: implemented
---
# consultation-management Specification

## Purpose
Implement structured inter-departmental consultation (adviesaanvraag) as a first-class entity in Procest. A consultation is a mini-case linked to a parent case, with its own lifecycle, assigned participants, documents, due dates, and formal response. This replaces informal email-based advice requests with tracked, auditable departmental coordination.

## Context
Dutch government case processing frequently requires consultation between departments and external advisory bodies: requesting fire safety advice from the brandweer for a building permit, environmental impact assessment from the milieudienst, or heritage review from the monumentencommissie. The Awb articles 3:5-3:9 define the legal framework for inter-departmental consultation (adviesrecht). Currently most municipalities handle this via email with document attachments, losing audit trail, version control, and deadline enforcement.

Procest's case infrastructure (cases, tasks, roles, statuses, documents) provides the foundation. ArkCase implements consultations as a full entity with its own pipeline, status lifecycle, document management, and department assignment -- essentially a "mini-case" linked to a parent case. Procest can achieve similar functionality using OpenRegister linked objects with a dedicated consultation schema and n8n workflows for lifecycle management.

## ADDED Requirements
### Requirement: Consultations MUST be first-class entities linked to parent cases
The system SHALL store consultations as first-class entities in OpenRegister with a dedicated schema, linked to a parent case via object relations.

#### Scenario: Create a consultation for a case
- **GIVEN** case `ZAAK-2026-000123` (type: `omgevingsvergunning`) requires fire safety advice
- **WHEN** a case worker clicks "Advies aanvragen" on the case detail view
- **THEN** a consultation creation dialog MUST appear with fields:
  - `parentZaak`: pre-filled with `ZAAK-2026-000123` (read-only)
  - `adviesInstantie`: the department or organization being consulted (searchable dropdown)
  - `onderwerp`: subject of the consultation (pre-filled with case title, editable)
  - `vraagstelling`: the specific question(s) being asked (rich text)
  - `uiterlijkeReactiedatum`: the deadline for response (date picker, default: 4 weeks from now)
  - `prioriteit`: priority level (`normaal`, `spoed`)
  - `bijlagen`: documents to include from the parent case (multi-select from case documents)
- **AND** upon save, a consultation object MUST be created in OpenRegister with status `open`
- **AND** the consultation number MUST be auto-generated (format: `ADV-{year}-{sequence}`)

#### Scenario: Multiple consultations per case with independent lifecycles
- **GIVEN** case `ZAAK-2026-000123` needs advice from both Brandweer and Welstandscommissie
- **WHEN** the case worker creates two consultations
- **THEN** both MUST be visible in the case's "Adviezen" tab
- **AND** each MUST have independent status, deadline, and document exchange
- **AND** the case detail MUST show a consultation count badge on the "Adviezen" tab

#### Scenario: Consultation references parent case bidirectionally
- **GIVEN** consultation `ADV-2026-0015` is created for case `ZAAK-2026-000123`
- **THEN** the consultation MUST have a `parentZaak` field referencing the case
- **AND** the case MUST have a `consultations` relation listing all linked consultations
- **AND** navigating from consultation to case and vice versa MUST be possible via clickable links

#### Scenario: Consultation data validation
- **GIVEN** a case worker is creating a consultation
- **WHEN** they attempt to save without filling `adviesInstantie`, `vraagstelling`, or `uiterlijkeReactiedatum`
- **THEN** the system MUST display validation errors for each missing required field
- **AND** the consultation MUST NOT be saved until validation passes

### Requirement: Consultations MUST have their own lifecycle with deadline enforcement
The system SHALL support an independent consultation lifecycle with status progression and configurable deadline warnings, independent of the parent case.

#### Scenario: Consultation lifecycle status transitions
- **GIVEN** consultation `ADV-2026-0015` is created with status `open`
- **THEN** the following status transitions MUST be enforced:
  - `open` -> `ontvangen` (consulted department acknowledges receipt)
  - `ontvangen` -> `in_behandeling` (department starts working on the advice)
  - `in_behandeling` -> `advies_uitgebracht` (department submits their advice)
  - `advies_uitgebracht` -> `afgesloten` (case worker reviews and closes the consultation)
  - Any open status -> `ingetrokken` (case worker withdraws the consultation request)
- **AND** backward transitions MUST NOT be allowed except by coordinator role

#### Scenario: Consulted department acknowledges receipt
- **GIVEN** consultation `ADV-2026-0015` has status `open`
- **WHEN** the Brandweer department user views their consultation inbox
- **AND** clicks "Ontvangen" on the consultation
- **THEN** the status MUST change to `ontvangen`
- **AND** the acknowledgment timestamp and user MUST be recorded
- **AND** the requesting case worker MUST receive a notification: "Adviesaanvraag ADV-2026-0015 ontvangen door Brandweer"

#### Scenario: Deadline warning at 5 days before due
- **GIVEN** consultation `ADV-2026-0015` has `uiterlijkeReactiedatum` of 2026-04-15
- **AND** the current date is 2026-04-10
- **AND** the status is `in_behandeling`
- **THEN** the system MUST send a warning notification to both the consulted department and the requesting case worker
- **AND** the consultation MUST appear highlighted in amber in all views

#### Scenario: Overdue consultation escalation
- **GIVEN** consultation `ADV-2026-0015` has `uiterlijkeReactiedatum` of 2026-04-15
- **AND** the current date is 2026-04-16
- **AND** the status is still `in_behandeling`
- **THEN** the consultation MUST be flagged as overdue (red highlight)
- **AND** a notification MUST be sent to the requesting case worker, the consulted department head, and the parent case's coordinator
- **AND** the overdue consultation MUST appear in the "Verlopen adviezen" section of the dashboard

#### Scenario: Request deadline extension
- **GIVEN** consultation `ADV-2026-0015` is `in_behandeling` with deadline 2026-04-15
- **WHEN** the consulted department requests a 2-week extension with justification "Externe expertise nodig"
- **THEN** the requesting case worker MUST receive an extension request notification
- **AND** the case worker MUST approve or reject the extension
- **AND** upon approval, the deadline MUST be updated to 2026-04-29
- **AND** the extension MUST be recorded in the consultation's audit trail

### Requirement: Consultations MUST support structured document exchange
The system SHALL support structured document exchange, allowing both the requester and the consulted party to attach and exchange documents within the consultation context.

#### Scenario: Attach context documents from parent case
- **GIVEN** case `ZAAK-2026-000123` has 5 documents including building plans and site photos
- **WHEN** creating consultation `ADV-2026-0015` for fire safety advice
- **THEN** the case worker MUST be able to select relevant documents from the case's document list
- **AND** selected documents MUST be linked to the consultation (not copied) via OpenRegister relations
- **AND** the consulted department MUST be able to view those documents from the consultation detail

#### Scenario: Consulted party uploads advice document
- **GIVEN** consultation `ADV-2026-0015` is `in_behandeling`
- **WHEN** the Brandweer user uploads their formal advice as "brandveiligheidsadvies-2026.pdf"
- **THEN** the document MUST be stored in the case's Nextcloud folder under subfolder "Adviezen/ADV-2026-0015/"
- **AND** the document MUST be linked to both the consultation and the parent case
- **AND** the requesting case worker MUST receive a notification: "Document ontvangen: brandveiligheidsadvies-2026.pdf van Brandweer"

#### Scenario: Document version management
- **GIVEN** the Brandweer uploads an initial advice document
- **AND** later uploads a revised version with corrections
- **THEN** both versions MUST be preserved (Nextcloud file versioning)
- **AND** the consultation's document list MUST show the latest version with a "Versiegeschiedenis" link
- **AND** the case worker MUST be notified of the revision

#### Scenario: Document access scoping
- **GIVEN** consultation `ADV-2026-0015` links 3 documents from the parent case
- **WHEN** the consulted department user views the consultation
- **THEN** they MUST see only the 3 linked documents, NOT all parent case documents
- **AND** they MUST NOT be able to access other case documents or other cases

### Requirement: Consultation responses MUST be structured with formal conclusions
The system SHALL support structured consultation responses with a formal conclusion enum and optional conditions that flow back to the parent case.

#### Scenario: Submit positive advice with conditions
- **GIVEN** consultation `ADV-2026-0015` asks "Is the building fire-safe?"
- **WHEN** the Brandweer user submits their response
- **THEN** the response form MUST include:
  - `advies`: enum value (`positief`, `positief_met_voorwaarden`, `negatief`, `niet_van_toepassing`)
  - `toelichting`: explanation text (mandatory for all values except `niet_van_toepassing`)
  - `voorwaarden`: list of conditions (enabled when `positief_met_voorwaarden` is selected), each with description and priority
  - `datum`: date the advice was given
  - `bijlagen`: uploaded advice documents
- **AND** the consultation status MUST change to `advies_uitgebracht`
- **AND** the requesting case worker MUST receive a notification with the advice summary

#### Scenario: Negative advice blocks case progression
- **GIVEN** consultation `ADV-2026-0015` receives advice `negatief` with toelichting "Brandtrap ontbreekt"
- **WHEN** the case worker views the parent case
- **THEN** the case MUST display a warning: "Negatief advies ontvangen van Brandweer"
- **AND** if the case type is configured to require positive advice for this consultation type, the case MUST NOT be progressable to the decision milestone until the negative advice is addressed

#### Scenario: Conditions from advice flow back as tasks on parent case
- **GIVEN** consultation `ADV-2026-0015` has advice `positief_met_voorwaarden` with 3 conditions
- **WHEN** the case worker views the parent case
- **THEN** the conditions MUST appear as a "Voorwaarden" checklist in the case detail
- **AND** each condition MUST be individually markable as addressed or not addressed
- **AND** the case worker MUST be able to link evidence documents to each condition
- **AND** the consultation MUST show the condition compliance status

#### Scenario: Request clarification on advice
- **GIVEN** consultation `ADV-2026-0015` has received advice that the case worker finds unclear
- **WHEN** the case worker clicks "Verduidelijking vragen" on the consultation
- **THEN** the consultation status MUST remain `advies_uitgebracht` (not revert to `in_behandeling`)
- **AND** a clarification request MUST be sent as a comment on the consultation
- **AND** the consulted department MUST receive a notification with the clarification question

### Requirement: Consultation events MUST appear in the parent case timeline
The system SHALL ensure all consultation lifecycle events are visible in the parent case's activity feed for full traceability.

#### Scenario: Consultation creation event in case timeline
- **GIVEN** case `ZAAK-2026-000123` has consultation `ADV-2026-0015` created
- **WHEN** viewing the case's ActivityTimeline component
- **THEN** the following event MUST appear: "Adviesaanvraag aangemaakt voor Brandweer (ADV-2026-0015)" with date and requester name

#### Scenario: Full consultation lifecycle in case timeline
- **GIVEN** consultation `ADV-2026-0015` progresses through its full lifecycle
- **WHEN** viewing the case timeline
- **THEN** the following events MUST appear chronologically:
  - "Adviesaanvraag aangemaakt voor Brandweer" (with date and requester)
  - "Brandweer heeft adviesaanvraag ontvangen" (with date)
  - "Brandweer is gestart met advies" (with date)
  - "Document ontvangen: brandveiligheidsadvies-2026.pdf" (with date)
  - "Brandweer heeft advies uitgebracht: positief met voorwaarden" (with date and summary)
  - "Adviesaanvraag afgesloten" (with date and closer)

#### Scenario: Overdue consultation warning in case timeline
- **GIVEN** consultation `ADV-2026-0015` is 3 days overdue
- **WHEN** viewing the case timeline
- **THEN** a warning event MUST appear: "Adviesaanvraag ADV-2026-0015 is verlopen (3 dagen over deadline)"
- **AND** the event MUST be visually distinct (red/amber indicator)

### Requirement: Consulted departments MUST have a dedicated inbox view
The system SHALL provide a dedicated inbox view for consulted departments, giving department users a centralized view of all consultations assigned to their department.

#### Scenario: Department consultation inbox
- **GIVEN** the Brandweer department has 5 open consultations across different cases
- **WHEN** a Brandweer user navigates to "Adviesaanvragen" in the sidebar
- **THEN** all 5 consultations MUST be listed with: consultation number, parent case number, subject, requesting department, deadline, and status
- **AND** overdue items MUST be sorted to the top and highlighted in red
- **AND** the list MUST support filtering by: status, requesting department, date range, and priority

#### Scenario: Claim consultation for handling
- **GIVEN** consultation `ADV-2026-0015` is assigned to the Brandweer department (group)
- **WHEN** Brandweer user "P. Jansen" clicks "Oppakken" on the consultation
- **THEN** the consultation MUST be assigned to "P. Jansen" as the individual handler
- **AND** the requesting case worker MUST receive a notification: "P. Jansen (Brandweer) behandelt adviesaanvraag ADV-2026-0015"

#### Scenario: Reassign consultation within department
- **GIVEN** consultation `ADV-2026-0015` is assigned to "P. Jansen"
- **WHEN** the department coordinator reassigns it to "M. de Vries"
- **THEN** the assignment MUST be updated
- **AND** both "P. Jansen" and "M. de Vries" MUST receive notifications
- **AND** the reassignment MUST be recorded in the consultation's audit trail

### Requirement: Dashboard MUST show consultation KPIs
The system SHALL provide dashboard KPIs for consultations, giving coordinators and department heads oversight of open consultations with performance metrics.

#### Scenario: My pending consultations widget
- **GIVEN** a Brandweer user has 3 open consultations assigned to their department
- **WHEN** they view the Procest dashboard
- **THEN** a "Openstaande adviesaanvragen" widget MUST show: count of open consultations, count of overdue consultations, and the 3 nearest deadlines
- **AND** clicking the widget MUST navigate to the filtered consultation inbox

#### Scenario: Consultation performance metrics for coordinators
- **GIVEN** 50 consultations were completed in Q1 2026
- **WHEN** a coordinator views the consultation analytics
- **THEN** the dashboard MUST show:
  - Average response time by department
  - On-time completion rate by department
  - Advice outcome distribution (positief/negatief/voorwaarden) by consultation type
  - Total consultations per case type
- **AND** departments with >20% overdue rate MUST be highlighted

#### Scenario: Consultation bottleneck detection
- **GIVEN** 8 consultations assigned to the Welstandscommissie are overdue
- **AND** the average response time has increased from 10 days to 25 days in the last month
- **WHEN** the daily analytics job runs
- **THEN** the coordinator MUST receive an alert: "Welstandscommissie: 8 verlopen adviezen, gemiddelde doorlooptijd gestegen naar 25 dagen"

### Requirement: Consultation types MUST be configurable per case type
The system SHALL support configurable consultation types per case type, defining which consultation types are available and whether they are mandatory or optional.

#### Scenario: Configure mandatory consultation for zaaktype
- **GIVEN** zaaktype `omgevingsvergunning` is being configured
- **WHEN** an admin defines consultation types
- **THEN** they MUST be able to add: "Brandveiligheid" (mandatory, default department: Brandweer, default deadline: 4 weeks)
- **AND** "Welstandstoets" (optional, default department: Welstandscommissie, default deadline: 3 weeks)
- **AND** mandatory consultations MUST be auto-created when a case of this type is created

#### Scenario: Mandatory consultation blocks case completion
- **GIVEN** case `ZAAK-2026-000123` has a mandatory consultation "Brandveiligheid" that is still `open`
- **WHEN** the case worker attempts to progress the case to the "Besluit" milestone
- **THEN** the system MUST block progression with message: "Verplicht advies 'Brandveiligheid' is nog niet ontvangen"
- **AND** the blocking consultations MUST be listed with links

#### Scenario: Optional consultation can be skipped
- **GIVEN** case `ZAAK-2026-000123` has an optional consultation "Welstandstoets" that was not created
- **WHEN** the case worker progresses the case to the decision milestone
- **THEN** the system MUST allow progression without the optional consultation
- **AND** no warning MUST be shown for optional consultations that were never created

### Requirement: Advisory bodies MUST be manageable as a registry
Departments and external advisory bodies that can receive consultations MUST be stored in a searchable registry.

#### Scenario: Configure advisory body
- **GIVEN** an admin accesses Settings > Adviesinstanties
- **WHEN** they add a new advisory body
- **THEN** they MUST provide: name, type (internal department / external organization), default contact group (Nextcloud group), email address, and specializations (tags)
- **AND** the advisory body MUST be stored as an OpenRegister object

#### Scenario: Search advisory bodies by specialization
- **GIVEN** 15 advisory bodies are configured, 3 of which have specialization "brandveiligheid"
- **WHEN** a case worker creates a consultation and searches for "brand"
- **THEN** the search results MUST show the 3 brandveiligheid-specialized bodies first
- **AND** all 15 bodies MUST still be selectable

#### Scenario: External advisory body receives consultation via email
- **GIVEN** advisory body "GGD Regio Utrecht" is an external organization with no Nextcloud account
- **WHEN** a consultation is created for this body
- **THEN** the system MUST send the consultation request via email to the configured email address
- **AND** the email MUST include: consultation number, subject, question, deadline, and a secure response link
- **AND** the external body MUST be able to respond via the secure link (uploading advice document and selecting advice outcome)

### Requirement: Parallel and sequential consultation patterns MUST be supported
The system SHALL support both parallel and sequential consultation patterns, as cases may require multiple consultations that can run in parallel or must complete sequentially.

#### Scenario: Parallel consultations with "wait for all" completion
- **GIVEN** case `ZAAK-2026-000123` has 3 mandatory consultations (Brandweer, Welstand, Milieu)
- **WHEN** all 3 are created simultaneously
- **THEN** all 3 MUST run independently with their own deadlines
- **AND** the case MUST NOT progress to the decision milestone until ALL 3 have status `advies_uitgebracht`
- **AND** the case detail MUST show a summary: "Adviezen: 2/3 ontvangen"

#### Scenario: Sequential consultation dependency
- **GIVEN** consultation "Milieuonderzoek" must complete before consultation "Bodemadvies" can start
- **WHEN** the admin configures consultation types for the case type
- **THEN** they MUST be able to define dependencies between consultation types
- **AND** "Bodemadvies" MUST NOT be createable until "Milieuonderzoek" has status `advies_uitgebracht`

#### Scenario: Consultation summary view on case
- **GIVEN** case `ZAAK-2026-000123` has 4 consultations (2 completed, 1 in progress, 1 not yet started)
- **WHEN** viewing the case's "Adviezen" tab
- **THEN** a summary bar MUST show: "2/4 adviezen ontvangen (1 in behandeling, 1 nog niet gestart)"
- **AND** each consultation MUST be listed with: number, department, status, advice outcome (if completed), and deadline
- **AND** a visual indicator MUST show the overall consultation progress

## Non-Requirements
- This spec does NOT cover public participation / inspraak (citizen consultation on policy decisions) -- that is a different process
- This spec does NOT cover automated advice generation via AI
- This spec does NOT cover legal advice management (advocaat-client privilege)

## Dependencies
- OpenRegister for consultation object storage (new `consultation` schema, `advisoryBody` schema, `adviceResponse` schema)
- OpenRegister `relationsPlugin` for linking consultations to parent cases and documents
- Existing case infrastructure (CaseDetail.vue, ActivityTimeline.vue) for integration
- n8n for email notifications, deadline monitoring, and external advisory body communication
- Nextcloud groups for department-based consultation assignment
- Nextcloud notification system (`OCP\Notification\IManager`) for lifecycle event notifications
- Dashboard.vue for consultation KPI widgets
- Milestone tracking spec for integration with mandatory consultation gates

---

### Current Implementation Status

**Not yet implemented.** No consultation-specific (adviesaanvraag) schemas, controllers, services, or Vue components exist in the Procest codebase.

**Foundation available:**
- Case detail view (`src/views/cases/CaseDetail.vue`) provides the integration point where a "Adviezen" tab could be added to the sidebar.
- Activity timeline component (`src/views/cases/components/ActivityTimeline.vue`) could display consultation events.
- Task management infrastructure (`src/views/tasks/`) could model consultation steps as tasks assigned to the consulted department.
- The `role` schema in OpenRegister could represent the consulted party's role on the case.
- The object store with `relationsPlugin` supports linking objects (consultations to parent cases).
- Document management (`filesPlugin`) supports attaching documents to objects, which could serve consultation document exchange.
- The `DeadlinePanel.vue` component could be reused for consultation deadline visualization.
- The `ParticipantsSection.vue` component demonstrates how to manage participants on a case, applicable to consultation participants.

**Partial implementations:** None.

### Standards & References

- **Awb articles 3:5-3:9 (Algemene wet bestuursrecht)**: Legal framework for inter-departmental consultation. Article 3:5 defines "adviseur" as a body authorized to advise. Article 3:6 requires reasonable deadline for advice. Article 3:9 states the decision-maker must verify the advice was produced diligently.
- **ZGW Zaken API (VNG)**: Consultations could be modeled as related zaken (deelzaken) or as custom zaakobjecten linked to the parent zaak.
- **GEMMA**: Adviesaanvraag is a standard interaction pattern in GEMMA ketenprocessen (chain processes). The GEMMA process architecture defines adviesverzoek/adviesreactie as a standard message pair.
- **CMMN 1.1**: Consultations map to the CaseTask concept -- a plan item that represents work done in a sub-case context. The sentry mechanism can model mandatory consultation gates.
- **Common Ground**: Inter-organizational data exchange follows Common Ground API-first principles. The "verwerken" and "notificeren" components are relevant for consultation workflow.
- **BIO (Baseline Informatiebeveiliging Overheid)**: Security requirements for sharing case information between departments and organizations. Access must be logged and permissions must be explicit.
- **ArkCase consultation plugin**: Implements consultations as a full entity (`acm-consultation-plugin`) with independent pipeline, status lifecycle, document management (Alfresco folders), and department assignment. Procest's approach uses OpenRegister schemas and n8n workflows instead of Java plugins and Activiti pipeline handlers.
- **Dimpact ZAC**: Does not have a dedicated consultation module -- inter-departmental coordination is handled via task assignment and group-based worklists. Procest's structured consultation management provides richer tracking and accountability.
