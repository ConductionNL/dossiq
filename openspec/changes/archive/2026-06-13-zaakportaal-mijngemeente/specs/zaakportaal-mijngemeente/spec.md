---
status: done
note: >-
  Citizen-portal "Mijn gemeente" delivered inside the host procest app (ADR-037).
  Backend services, schemas, controller and the four citizen forms (DocumentList,
  MessagingWidget, BezwaarForm, KlachtForm) are shipped and tested (PHPUnit 46,
  vitest 53, defensive Playwright). The original app-shell/router tasks
  (TASK-ZMP-26, TASK-ZMP-28) are SUPERSEDED by the procest manifest-v2 migration
  (menu + routes are now declarative in src/manifest.d/50-zaakportaal.json), and
  were not rebuilt. Live-instance items (DigiD/eHerkenning edge session, IP
  binding, full E2E/axe/pentest, n8n fan-out, subsidie via opencatalogi) remain
  DEFERRED with reasons in tasks.md.
---
# zaakportaal-mijngemeente Specification

## Purpose

Zaakportaal ("Mijn gemeente") is a citizen-facing web portal that grants authenticated burgers and bedrijven direct, real-time access to their active cases, documents, and case status with the municipality. It implements the Wdo-mandated authentication (DigiD, eHerkenning), supports delegation via DigiD Machtigen and eHerkenning Ketenmachtiging, and exposes case data read-only from Procest. Citizens can download documents, send messages to case handlers, file complaints and objections, and manage notification preferences — all logged for audit and compliance.

## Context

Dutch municipalities must provide transparent access to case information under the Wet Digitale Overheid (Wdo) and broader accessibility principles (WCAG 2.2 AA). Citizens currently receive status updates passively via Berichtenbox or telephone. A dedicated self-service portal reduces call-center load, improves citizen satisfaction, and centralizes the citizen experience away from the internal case-handling system (Procest).

Zaakportaal integrates with existing municipal infrastructure: it reads from Procest and OpenRegister, posts messages and objections back via restricted APIs, and leverages OpenConnector for authentication. All data is transient (no portal database); all actions are logged to OpenRegister.

## ADDED Requirements

### Requirement: DigiD and eHerkenning authentication with Wdo-mandated trust levels

REQ-POR-001: The system SHALL authenticate citizens via DigiD and businesses via eHerkenning, enforcing minimum trust level "substantieel", and SHALL reject lower trust levels and non-supported authentication methods.

#### Scenario: Burger logs in via DigiD
- **GIVEN** a burger navigates to mijn.gemeente.nl
- **WHEN** she clicks "Inloggen" and selects "Persoonlijk account (DigiD)"
- **THEN** she is redirected to DigiD login (via OpenConnector)
- **AND** after entering credentials, OpenConnector returns her BSN and `betrouwbaarheidsniveau`
- **AND** if `betrouwbaarheidsniveau >= "substantieel"` (i.e., "substantieel" or "hoog"), a session is created with `ingelogdAls.type = "burger"`, `ingelogdAls.bsn = "123456789"`, and `sessieVerloopt` set to 15 minutes from now
- **AND** she is redirected to "Mijn zaken" overview page

#### Scenario: Low-trust DigiD attempt is rejected
- **GIVEN** a user has a DigiD account with `betrouwbaarheidsniveau = "laag"`
- **WHEN** she logs in via DigiD
- **THEN** OpenConnector returns her BSN and trust level to the portal
- **AND** the portal displays an error message: "Je vertrouwensniveau is onvoldoende. Log in via een verificatiemiddel op niveau 'substantieel' of hoger" ("Your trust level is insufficient...")
- **AND** the session is NOT created
- **AND** the attempt is logged to OpenRegister audit trail

#### Scenario: Ondernemer logs in via eHerkenning
- **GIVEN** an ondernemer for "Janssen & Partners B.V." navigates to mijn.gemeente.nl
- **WHEN** he clicks "Inloggen" and selects "Bedrijfsaccount (eHerkenning)"
- **THEN** he is redirected to eHerkenning login
- **AND** after authentication, OpenConnector returns his `kvkNummer`, `vestigingsnummer`, company name, his personal name, and `betrouwbaarheidsniveau`
- **AND** if trust level >= "substantieel-plus", a session is created with `ingelogdAls.type = "bedrijf"`, `ingelogdAls.kvkNummer = "12345678"`, and role stored as `namensPersoon`
- **AND** the session is valid for 15 minutes of inactivity

### Requirement: Session binding to IP and user-agent for security

REQ-POR-002: The system SHALL bind each session token to the client's IP address and user-agent string, and SHALL invalidate the session if either changes.

#### Scenario: Session is bound on creation
- **GIVEN** a burger logs in from IP 203.0.113.42 using Safari/Chrome browser
- **WHEN** the session is created
- **THEN** the session JWT includes claims: `ip = "203.0.113.42"`, `userAgent = "Mozilla/5.0 ... Safari/..."`
- **AND** subsequent requests must include the same IP and user-agent in headers

#### Scenario: Request from different IP is rejected
- **GIVEN** a burger has an active session from IP 203.0.113.42
- **WHEN** she makes a request from IP 203.0.113.99 (e.g., mobile network changed)
- **THEN** the portal validates the incoming request IP against the session's stored IP
- **AND** the request is rejected with a 403 Forbidden error
- **AND** the session is terminated
- **AND** the user is redirected to login with a message: "Je sessie is verlopen vanwege een IP-verandering. Log alsjeblieft opnieuw in."
- **AND** the mismatch is logged to OpenRegister audit trail

### Requirement: DigiD Machtigen and eHerkenning Ketenmachtiging support

REQ-POR-003: The system SHALL allow a gemachtigde (wettelijk vertegenwoordiger, bewindvoerder, mantelzorger) to log in on behalf of another person using DigiD Machtigen, and SHALL similarly allow professional advisors to act via eHerkenning Ketenmachtiging.

#### Scenario: Mantelzorger logs in via DigiD Machtigen for a dependent
- **GIVEN** a mantelzorger has been granted a DigiD Machtigen delegation for burgerBS = 123456789
- **WHEN** she logs in and authorizes the delegation at the DigiD Machtigen challenge
- **THEN** OpenConnector returns: her own BSN (555666777), the dependent's BSN (123456789), `machtigingsType = "mantelzorger"`, `geldig_tot = "2027-04-15"`
- **AND** a session is created with `ingelogdAls.type = "gemachtigde"`, `ingelogdAls.bsn = "555666777"` (the gemachtigde), and a `machtiging` object with the dependent's details
- **AND** when she fetches "Mijn zaken", the portal queries cases where the dependent (123456789) is involved
- **AND** in the UI, she sees a banner: "Ingelogd als [Gemachtigde] namens [Dependent naam] tot [datum]"

#### Scenario: Machtiging restriction by zaaktype
- **GIVEN** a gemachtigde's DigiD Machtigen delegation is limited to WMO (social care) cases only
- **WHEN** she logs in and fetches the case list
- **THEN** OpenConnector includes `machtiging.zaaktypeBeperking = ["WMO-aanvraag"]`
- **AND** the portal filters the returned cases to show only those with zaaktype "WMO-aanvraag"
- **AND** if she tries to access a non-WMO case via direct URL (e.g., /cases/zaak-omgeving-123), the portal returns a 403 and logs the unauthorized attempt

#### Scenario: Professional advisor via eHerkenning Ketenmachtiging
- **GIVEN** an accountant acting on behalf of "Janssen & Partners B.V." logs in via eHerkenning Ketenmachtiging
- **WHEN** OpenConnector returns his personal BSN and the company's KvK, plus `machtiging.type = "professionele-bewindvoerder"`
- **THEN** a session is created with both the company KvK and his personal identity
- **AND** when fetching cases, the portal returns cases for that KvK number
- **AND** a UI banner shows: "Ingelogd als [Advisor naam] voor onderneming [Company naam]"

### Requirement: Case overview filtered by BSN or KvK

REQ-POR-004: Upon login, the system SHALL retrieve all cases from Procest where the citizen (or their delegation) is involved as aanvrager, geadresseerde, or belanghebbende, and SHALL filter by BSN (for burgers/gemachtigden) or KvK (for ondernemers).

#### Scenario: Burger sees her cases
- **GIVEN** burger with BSN 123456789 has the following cases in Procest:
  - Z/2026/09128 (Omgevingsvergunning, status vergunning-verleend)
  - Z/2026/04456 (Subsidie, status in-behandeling)
- **WHEN** she logs in and navigates to "Mijn zaken"
- **THEN** the portal queries the Procest CaseService with filter `bsn = 123456789`
- **AND** both cases are retrieved and displayed as ZaakOverzichtItem entries
- **AND** the list shows: kenmerk, zaaktype, onderwerp, status, ingediendOp, termijnen

#### Scenario: Ondernemer sees business cases
- **GIVEN** ondernemer logs in for KvK 12345678 and has cases:
  - Z/2026/04499 (Horeca-vergunning, status in-behandeling)
  - Z/2026/00672 (Evenementenvergunning, status afgehandeld)
- **WHEN** he navigates to "Mijn zaken"
- **THEN** the portal queries Procest with filter `kvkNummer = 12345678`
- **AND** both cases appear in the list

#### Scenario: No cases for a citizen
- **GIVEN** a burger with BSN 999999999 has no active cases in Procest
- **WHEN** she logs in and navigates to "Mijn zaken"
- **THEN** the portal displays a message: "Je hebt momenteel geen actieve zaken. Dien een nieuwe aanvraag in op [link to gemeente website]."

### Requirement: Document access control — only citizen-addressable documents

REQ-POR-005: The system SHALL only display and allow download of documents marked with `downloadbaarVoor = ["aanvrager"]` or equivalent, and internal documents (adviezen, ambtelijke notities) MUST be completely hidden.

#### Scenario: Citizen downloads only her documents
- **GIVEN** case Z/2026/09128 contains 12 documents in Procest:
  - 6 marked `downloadbaarVoor = ["aanvrager"]` (Aanvraagformulier, Bouwtekening, Beschikking, etc.)
  - 6 internal only (Interne advies, Notities behandelaar, etc.)
- **WHEN** the burger (aanvrager) opens the case detail
- **THEN** the portal queries the DocumentService for documents linked to this case
- **AND** DocumentService filters by `downloadbaarVoor` and returns only the 6 public documents
- **AND** the 6 internal documents are completely omitted from the list (not even as titles)
- **AND** if she somehow tries to download an internal document directly (e.g., via saved URL), the system returns 403 Forbidden and logs the attempt

#### Scenario: Geadresseerde in bezwaar sees the decision document
- **GIVEN** burger is the geadresseerde (not the original aanvrager) in a bezwaar case Z/2026/bezw-001
- **AND** the besluit document is marked `downloadbaarVoor = ["geadresseerde"]`
- **WHEN** she opens the case
- **THEN** the besluit document is displayed and downloadable
- **AND** documents marked only for "aanvrager" are hidden

#### Scenario: Audit logging of document access
- **GIVEN** a burger downloads document "Beschikking_Z2026_09128.pdf"
- **WHEN** the download completes
- **THEN** the AuditLogger writes a record to OpenRegister with:
  - `action = "document-download"`
  - `actor = burger BSN`
  - `objectId = document ID`
  - `timestamp = now`
  - `result = "success"`

### Requirement: Status timeline visualization with deadline tracking

REQ-POR-006: The system SHALL display a visual timeline of case status transitions with planned and wettelijk deadlines, and SHALL highlight remaining time, warn when deadlines approach, and clearly indicate if a deadline has been missed.

#### Scenario: Timeline for active omgevingsvergunning case
- **GIVEN** case Z/2026/09128 (Omgevingsvergunning) has:
  - Status history: ingediend (2026-01-12) → ontvankelijkheid-getoetst (2026-01-15) → inhoudelijk-getoetst (2026-02-08) → vergunning-verleend (2026-04-02)
  - `afhandelTermijnWettelijk = "8 weken"` (ends 2026-04-15)
  - Current date = 2026-04-10 (5 days after final decision, 5 days left in wettelijke termijn)
- **WHEN** the burger opens the case detail
- **THEN** StatusTimeline.vue renders:
  - Completed steps as circles with checkmarks: ingediend (Jan 12), ontvankelijkheid-getoetst (Jan 15), inhoudelijk-getoetst (Feb 8), vergunning-verleend (Apr 2)
  - Each step labeled with date and brief description
  - A progress bar showing deadline: [5 days used so far] ███████████░░░░ [5 days remaining until Apr 15]
  - Color: green for on-track
  - Text: "Behandeltermijn: tot 15 april 2026 (nog 5 dagen)"

#### Scenario: Warning when deadline approaches
- **GIVEN** a case has `afhandelTermijnEinde = "2026-03-30"` and current date is 2026-03-25 (5 days left)
- **AND** the case status is still "in-behandeling" (not completed)
- **WHEN** the timeline is rendered
- **THEN** the deadline indicator turns orange/yellow
- **AND** text appears: "Behandeling loopt naar deadline 30 maart (nog 5 dagen)"

#### Scenario: Deadline exceeded
- **GIVEN** case has `afhandelTermijnEinde = "2026-03-30"`, current date is 2026-04-05, status still "in-behandeling"
- **WHEN** the timeline is rendered
- **THEN** the deadline indicator is red
- **AND** text displays: "⚠️ Behandeling heeft termijn overschreden (sinds 30 maart). Neem contact op met je behandelaar."
- **AND** an action button "Vraag om uitleg" is shown (opens messaging widget)

### Requirement: Messaging between citizen and case handler

REQ-POR-007: Citizens SHALL be able to send messages to their case handler, with optional file attachments. Messages SHALL be stored in Procest and trigger notifications to the handler; handler replies SHALL surface in the portal.

#### Scenario: Citizen sends a question message
- **GIVEN** burger is viewing case Z/2026/09128 with treatment handler "K. Bakker"
- **WHEN** she clicks "Bericht sturen", types "Kunt u uitleggen wat voorwaarde 3 betekent?", and clicks Send
- **THEN** the portal creates a PortaalBericht with:
  - `zaakId = "zaak-2026-vth-09128"`
  - `verzender = {type: "burger", bsn: "123456789", naam: "M.A. Janssen-de Vries"}`
  - `onderwerp = "Vraag"` (auto-generated or user-specified)
  - `inhoud = "Kunt u uitleggen wat voorwaarde 3 betekent?"`
  - `verzondenOp = now`
- **AND** the message is posted to Procest API (PUT /messages)
- **AND** an n8n notification workflow is triggered, sending the handler an email: "Nieuwe vraag van burger op zaak Z/2026/09128"
- **AND** the message appears in the citizen's "Berichten" tab with status "Verzonden op [timestamp]"

#### Scenario: Handler's reply is visible in the portal
- **GIVEN** the handler K. Bakker replied to the message within Procest (backend system)
- **WHEN** the citizen refreshes the case detail page
- **THEN** the portal queries Procest for messages on this case
- **AND** her original message is shown with the handler's reply below it
- **AND** she receives a notification (email or Berichtenbox) saying "Je behandelaar K. Bakker heeft een antwoord gegeven"

#### Scenario: Message with file attachment
- **GIVEN** a citizen wants to attach a document to her question
- **WHEN** she clicks "Bericht sturen", types a message, clicks "Bijlage toevoegen", selects a PDF file, and sends
- **THEN** the file is uploaded to OpenRegister (via the portal's FileService)
- **AND** the PortaalBericht includes a reference to the uploaded file
- **AND** the file is scanned for malware before being stored (integration with antivirus if configured)
- **AND** a copy is attached to the audit trail

### Requirement: Bezwaar (objection) filing within legal deadline

REQ-POR-008: When a decision is issued, the citizen SHALL be able to file a formal objection (bezwaarschrift) if the deadline (typically 6 weeks after decision) has not passed. The system SHALL validate timeliness, collect the objection grounds, and create a new bezwaar case in Procest.

#### Scenario: Bezwaar form appears when deadline is open
- **GIVEN** case Z/2026/09128 has a decision "Beschikking omgevingsvergunning" issued on 2026-04-02
- **AND** the bezwaarschrifttermijn is 6 weeks, so deadline is 2026-05-14
- **AND** current date is 2026-04-12 (32 days before deadline)
- **WHEN** the citizen opens the case detail
- **THEN** the "Mogelijke acties" section shows a button: "Bezwaar indienen"
- **AND** when clicked, a BezwaarForm opens with:
  - Pre-filled: `tegenZaakId`, decision title, decision date
  - Fields: Motiveringveld (text area), file upload area for supporting documents
  - A checkbox: "Ik ben het ermee eens dat mijn gegevens voor deze procedure worden gebruikt"

#### Scenario: Bezwaar is submitted within deadline
- **GIVEN** the form is filled with:
  - `motivering = "Ik ben het niet eens met voorwaarde 3 omdat de dakhellingshoek onredelijk is..."`
  - 1 attachment (architect brief)
- **WHEN** the citizen clicks "Dien bezwaar in"
- **THEN** the system validates:
  - Deadline has not passed: current date < 2026-05-14 ✓
  - Motivering field is not empty ✓
  - Files are not malware-infected ✓
- **AND** a PortaalVerzoek is created with:
  - `soort = "bezwaarschrift"`
  - `tegenZaakId = "zaak-2026-vth-09128"`
  - `tegenBeschikkingId = [decision ID]`
  - `motivering = [text]`
  - `ingediendOp = now`
  - `binnenTermijn = true`
- **AND** Procest creates a new bezwaarzaak: `zaak-2026-bezw-04711`
- **AND** the citizen receives an email: "Uw bezwaarschrift is ontvangen op [date]" with reference number
- **AND** the case detail shows: "✓ Bezwaar ingediend op [date] (zaak Z/2026-bezw-04711)"

#### Scenario: Bezwaar deadline has expired
- **GIVEN** current date is 2026-05-20 (6 days after the 2026-05-14 deadline)
- **WHEN** the citizen opens the case detail
- **THEN** the "Bezwaar indienen" button is disabled or hidden
- **AND** a message appears: "De termijn voor bezwaar (tot 14 mei) is verlopen. Neem contact op met de gemeente voor meer informatie."
- **AND** a link to "Vraag om uitleg" (messaging) is provided

### Requirement: Klacht (complaint) filing and optional subsidie aanvragen

REQ-POR-009: Citizens SHALL be able to file formal complaints (klacht) and subsidie-aanvragen independently of any case. Klacht intake SHALL route to the complaint management workflow; subsidie-aanvragen SHALL be directed to the appropriate application system.

#### Scenario: Klacht intake form
- **GIVEN** a citizen navigates to a standalone "Klacht indienen" form (accessible from the main menu or homepage)
- **WHEN** she selects "Klacht indienen"
- **THEN** KlachtForm opens with fields:
  - `categorie`: dropdown (Bejegening, Doorlooptijd, Communicatie, Medische/Zorgkwaliteit, Andere)
  - `omschrijving`: text area ("Beschrijf je klacht")
  - `betrokkenMedewerker`: optional (name or department of the employee)
  - `aanvullingenVerzenden`: optional checkbox to allow handler to request more info

#### Scenario: Klacht is submitted
- **GIVEN** the form is filled with:
  - `categorie = "Bejegening"`
  - `omschrijving = "De medewerker was onbeleefd toen ik naar het raam ging..."`
- **WHEN** the citizen clicks "Dien klacht in"
- **THEN** a PortaalVerzoek is created with `soort = "klachtschrift"`
- **AND** Procest creates a complaint case (zaaktype = "klacht") with status "ontvangen"
- **AND** the citizen receives an email receipt: "Uw klacht is ontvangen. Referentie: KL-2026-XXXXX"
- **AND** the complaint is routed to the klachtencoördinator

#### Scenario: Subsidie-aanvraag selection
- **GIVEN** a citizen navigates to "Subsidie aanvragen"
- **WHEN** the SubsidieForm loads
- **THEN** it queries opencatalogi for available subsidies in the municipality
- **AND** displays a list: "Dakisolatie huiseigenaren 2026", "Monument gevelrenovatie", etc.
- **AND** for each subsidy, a button "Aanvragen" links to either:
  - An external application system URL (gemeente website)
  - An embedded form if available (future phase)

### Requirement: Notification preferences management

REQ-POR-010: Citizens SHALL be able to choose which notification channels (email, Berichtenbox, SMS) and which events (status change, document added, message from handler, deadline reminder) trigger notifications. Berichtenbox MUST always remain active for statutory notifications.

#### Scenario: Citizen disables email notifications
- **GIVEN** a citizen navigates to "Instellingen > Notificaties"
- **WHEN** she views her current preferences (email enabled, Berichtenbox enabled)
- **AND** unchecks the "Ontvang e-mailnotificaties" checkbox
- **THEN** the portal submits a PATCH to update PortaalNotificatieVoorkeur with `kanalen.email.actief = false`
- **AND** Procest persists this preference
- **AND** a confirmation message displays: "Voorkeur opgeslagen. Je ontvangt voortaan geen e-mailnotificaties meer (behalve wettelijk verplichte berichten)."

#### Scenario: Selective event notifications
- **GIVEN** the citizen opens the event preferences
- **WHEN** she unchecks "Statuswijziging" but leaves "Bericht van behandelaar" enabled
- **THEN** the portal sends updates:
  - `gebeurtenissen.statuswijziging = false`
  - `gebeurtenissen.berichtVanBehandelaar = true`
- **AND** n8n's notification workflow checks these preferences before sending emails

#### Scenario: Email verification when adding new email address
- **GIVEN** a citizen wants to change her notification email from marja@example.nl to marja.new@example.nl
- **WHEN** she enters the new email and clicks "Opslaan"
- **THEN** the portal sends a verification email to marja.new@example.nl with a link
- **AND** the new email is marked `geverifieerd = false` until she clicks the link
- **AND** the old email remains active for notifications until the new one is verified
- **AND** if she doesn't verify within 7 days, the new email is discarded

#### Scenario: Berichtenbox always active for legal notifications
- **GIVEN** a citizen disables email and wants to disable Berichtenbox
- **WHEN** she unchecks "Ontvang berichten via Berichtenbox"
- **THEN** the portal shows a warning: "Berichtenbox is wettelijk verplicht voor officiële bekendmakingen (beschikkingen, dwangsommen). Dit kan niet worden uitgeschakeld."
- **AND** the Berichtenbox checkbox remains checked and disabled

### Requirement: Accessibility — WCAG 2.2 AA compliance

REQ-POR-011: The portal MUST meet Web Content Accessibility Guidelines (WCAG) 2.2 Level AA. All interactive elements MUST be keyboard accessible; all information conveyed visually MUST have a text alternative; color contrast MUST meet WCAG standards.

#### Scenario: Keyboard navigation through case list
- **GIVEN** a citizen uses only a keyboard (no mouse) to navigate the portal
- **WHEN** she presses Tab to navigate through the case list
- **THEN** focus moves visibly through each case row
- **AND** pressing Enter on a case opens the detail page
- **AND** all buttons (Bericht sturen, Bezwaar indienen) are reachable via Tab and activatable via Enter/Space

#### Scenario: Screen reader announces case timeline
- **GIVEN** a citizen uses a screen reader (NVDA, JAWS, VoiceOver)
- **WHEN** she navigates to the timeline section
- **THEN** the screen reader announces:
  - "Status timeline, 4 steps completed"
  - "Step 1: Ingediend, January 12, 2026, Aanvraag ontvangen"
  - "Step 2: Ontvankelijkheid getoetst, January 15, 2026, Aanvraag is volledig"
  - etc.
  - "Deadline: April 15, 2026, 5 days remaining, on track"

#### Scenario: Sufficient color contrast in timeline
- **GIVEN** the timeline uses color to indicate status (green = on-track, orange = approaching, red = exceeded)
- **WHEN** measured with a contrast checker (e.g., WebAIM)
- **THEN** all text against colored backgrounds meets WCAG AA minimum contrast ratio of 4.5:1 for text

#### Scenario: Form error messages are associated with inputs
- **GIVEN** a citizen submits a bezwaarschrift form without filling "Motivering"
- **WHEN** the form validation fails
- **THEN** an error message appears inline near the field
- **AND** the error is programmatically linked to the input via `aria-describedby`
- **AND** the input receives focus with `aria-invalid="true"`
- **AND** a screen reader announces "Motivering, text area, invalid, Veld is verplicht"

### Requirement: NL Design System styling and components

REQ-POR-012: The portal SHALL use NL Design System components (Button, Link, TextInput, Select, etc.) for visual consistency and to align with government digital standards.

#### Scenario: Buttons follow NL Design System
- **GIVEN** the portal uses buttons throughout (Bezwaar indienen, Bericht sturen, etc.)
- **WHEN** rendered
- **THEN** all buttons match the NL Design System Button component style:
  - Rounded corners, standard padding, NL blue primary color (#007BC7), white text
  - Hover state: darker blue, visible focus indicator (outline)
  - Disabled state: gray background, lower opacity

#### Scenario: Form inputs use NL Design System
- **GIVEN** forms (bezwaar, klacht, messaging) use text inputs and textareas
- **WHEN** rendered
- **THEN** all inputs use the NL Design System TextInput/Textarea style:
  - Bordered boxes, left-aligned labels, help text below
  - Focus state: blue border + outline
  - Error state: red border + error message

## Cross-Cutting Requirements

### Security & Compliance

**REQ-SEC-001:** All API requests must be authenticated and use HTTPS. Session tokens are short-lived JWTs (15 min TTL) with IP + user-agent binding.

**REQ-SEC-002:** Passwords and sensitive data are never logged. All data access is logged to OpenRegister audit trails, including failed authentication attempts.

**REQ-SEC-003:** File uploads are scanned for malware and restricted to common document types (PDF, DOC, DOCX, XLS, XLSX).

**REQ-SEC-004:** The Procest API layer enforces role-based access control: citizens can only read their own cases and write to restricted endpoints (messages, objections, complaints).

### Performance

**REQ-PERF-001:** Case list loads within 2 seconds (P95). Case detail with timeline and documents loads within 3 seconds.

**REQ-PERF-002:** No client-side caching of case data; all data is fetched fresh on each page load to ensure freshness.

### Privacy & Data Minimization

**REQ-PRIV-001:** The portal stores no persistent citizen data; all session data is in-memory or browser-only.

**REQ-PRIV-002:** Treatment handler details are anonymized where possible: show first name + department only, not full email or phone.

**REQ-PRIV-003:** All citizen actions are logged to OpenRegister for audit and compliance purposes, with retention policies aligned to Wpg (Wet Persoonlijke Gegevens).

## Integration Standards

- **Wet Digitale Overheid (Wdo):** DigiD/eHerkenning authentication at "substantieel" minimum.
- **WCAG 2.2 AA:** Web accessibility standard.
- **NL Design System:** Visual and component consistency.
- **OpenAPI 3.0:** REST API documentation.
- **OWASP ASVS 4.0 Level 2:** Security baseline.
- **Forum Standaardisatie "pas toe of leg uit":** SAML 2.0, OpenID Connect, HTTPS, JSON Web Tokens (JWT).
