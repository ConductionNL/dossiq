---
retrofit: true
status: done
note: Implemented and archived 2026-06-13 (change consultation-management). Schemas (consultation/advisoryBody/adviceResponse), ConsultationService + AdvisoryBodyService, ConsultationController (incl. public token route), Vue dialogs/panel/dashboard/settings tabs, n8n workflows, and i18n shipped. CN-08 (dedicated IEventDispatcher notification service) and CN-12 (live double-import idempotency check) deferred ([~]); timeline events currently surface via n8n + the ActivityTimeline component.
---

# Consultation Management Specification

## Purpose

@e2e exclude Consultation management is V1; consultation lifecycle endpoints are backend-only in the current build.

Provide first-class lifecycle management for inter-departmental consultations (adviesaanvragen) — separate entities linked to a parent zaak, with their own create/list/status-transition/advice-submission/overdue-detection surface — so that a casehandler can request structured advice from another department, the advising department can respond with one of four codified outcomes, and the system can highlight requests that have passed their deadline.
## Requirements

### REQ-001: Consultation REST endpoints (index, create, updateStatus, submitResponse, overdue)

The system SHALL expose five `@NoAdminRequired` JSON endpoints on `ConsultationController` — `index(caseId)`, `create()`, `updateStatus(id)`, `submitResponse(id)`, `overdue()` — that wrap `\RuntimeException` payloads as HTTP 400 `{error: <message>}` and delegate all business logic to `ConsultationService`.

#### Scenario: Read endpoints

- WHEN `index(caseId)` or `overdue()` is called
- THEN the controller SHALL return `{results: [...]}` with the service's response (empty array on OpenRegister unavailable, not an error)

#### Scenario: Write-endpoint envelope

- WHEN `create`, `updateStatus`, or `submitResponse` is called
- THEN the controller SHALL read `getContent()`, JSON-decode it (falling back to `[]` on non-array), delegate, and catch `\RuntimeException` returning HTTP 400 `{error: <message>}`
- AND `create` SHALL return HTTP 201 on success

### REQ-002: Consultation creation with required-field guards

The system SHALL require both `parentZaak` (the case the consultation belongs to) and `adviesInstantie` (the department or organisation being asked) on creation, default `status` to `open`, stamp `createdAt` with ISO 8601 timestamp, and persist via OpenRegister.

#### Scenario: Missing required field

- WHEN `createConsultation` is called with empty `parentZaak` or empty `adviesInstantie`
- THEN the service SHALL throw `\RuntimeException('parentZaak is required')` or `\RuntimeException('adviesInstantie is required')` before invoking OpenRegister

#### Scenario: OpenRegister unavailable

- WHEN OpenRegister is unavailable, the consultation schema is unconfigured, or the register id is empty
- THEN the service SHALL throw `\RuntimeException('OpenRegister is not available')` or `\RuntimeException('Consultation schema not configured')`

#### Scenario: Success shape

- WHEN creation succeeds
- THEN the service SHALL return `{id: <new uuid>, status: 'open'}` and log `'Consultation created: <uuid> for case <parentZaak>'`

### REQ-003: Consultation status lifecycle with afgesloten timestamp

The system SHALL accept status transitions only to one of `open`, `in_behandeling`, `advies_uitgebracht`, `afgesloten`. When the target status is `afgesloten`, the service SHALL stamp `closedAt` with the current timestamp before persisting.

#### Scenario: Reject invalid status

- WHEN `updateStatus` is called with a status outside the enum
- THEN the service SHALL throw `\RuntimeException('Invalid status: <value>')` before contacting OpenRegister

#### Scenario: Closure stamps closedAt

- WHEN `updateStatus` transitions to `afgesloten`
- THEN the update payload SHALL include `closedAt = date('Y-m-d\TH:i:s')` and the persisted object SHALL carry both `status` and `closedAt`

#### Scenario: Other transitions update only status

- WHEN transitioning to `open`, `in_behandeling`, or `advies_uitgebracht`
- THEN the update payload SHALL contain only `{status: <new>}` and the service SHALL return `{id, status}`

### REQ-004: Advice response submission with enum validation

The system SHALL accept advice responses with `advies` constrained to `positief`, `positief_met_voorwaarden`, `negatief`, `niet_van_toepassing`, optional `toelichting` (free text), and optional `voorwaarden` (any structured payload, JSON-encoded for storage); on submission it SHALL stamp `adviesDatum` (today, date only) and transition `status` to `advies_uitgebracht`.

#### Scenario: Reject invalid advies enum

- WHEN `submitResponse` is called with `advies` not in the four-value enum
- THEN the service SHALL throw `\RuntimeException('Invalid advice type: <value>')` before contacting OpenRegister

#### Scenario: Conditions get JSON-encoded

- WHEN `voorwaarden` is supplied
- THEN the service SHALL `json_encode` it into the persisted record; absent input SHALL persist as `null`

#### Scenario: Success shape and side effect

- WHEN submission succeeds
- THEN the service SHALL set `adviesDatum = date('Y-m-d')`, set `status = 'advies_uitgebracht'`, log `'Consultation <id> advice submitted: <advies>'`, and return `{id, advies, status: 'advies_uitgebracht'}`

### REQ-005: Overdue consultation detection via uiterlijkeReactiedatum

The system SHALL surface consultations whose `uiterlijkeReactiedatum` is before today and whose status is still `open` or `in_behandeling`.

#### Scenario: Filter scope

- WHEN `getOverdueConsultations` runs
- THEN it SHALL fetch up to 200 consultations with `status=open` AND 200 with `status=in_behandeling`, merge them, and filter to those with non-empty `uiterlijkeReactiedatum < today (Y-m-d)`

#### Scenario: OpenRegister unavailable

- WHEN OpenRegister is unavailable or the schema is unconfigured
- THEN the service SHALL return an empty array (no error)

#### Notes

- The 200-item per-status cap is a hardcoded pagination limit; on instances with very large consultation backlogs this is observed-but-suspicious and may silently drop overdue items beyond that window — flagged for future tightening.

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

