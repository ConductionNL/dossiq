---
status: proposed
---

# B&W Parafering & Besluitvorming Specification

## Purpose

B&W parafering covers the ambtelijk (civil servant) workflow for preparing, reviewing, and approving proposals before they reach the College van B&W for formal decision-making. The bestuurlijk (political) part -- agenda management, vergadering, and besluitenlijst -- is handled by external RIS systems (iBabs, NotuBiz). This spec covers the ambtelijk workflow and the connector to the RIS.

**Tender demand**: Found in 20+ tenders (29% of all, higher among generic zaaksysteem tenders). B&W besluitvorming is the #6 Nice-to-have but weighs heavily in scoring (typically 3-8% of total score, up to 68 points).
**Standards**: BPMN 2.0 (process modeling), ZGW Besluiten API, ZDS (Zaak-Document Services for legacy RIS), CMMN 1.1 (HumanTask for parafering steps)
**Feature tier**: V1 (ambtelijk parafering, sequential routing, audit trail), V2 (parallel parafering, mobile parafering, iBabs/NotuBiz connector, vergaderbeheer)

**Competitive context**: Dimpact ZAC implements decision management via the ZGW BRC API with besluittype validation, publication date handling, and document linking. ZAC does NOT include B&W parafering workflow -- that is handled externally. Flowable's CMMN engine can model parafeerroutes as sequential/parallel HumanTasks with configurable completion rules. ArkCase and CaseFabric both provide full approval workflows with configurable routing. Procest should implement parafering as OpenRegister objects with task-based routing, leveraging the existing task management infrastructure.

## Standard Workflow (10-Step Process)

Reconstructed from 20+ tender analyses, this is the standard B&W besluitvormingsproces:

| Step | Actor | Action | System |
|------|-------|--------|--------|
| 1 | Steller | Creates advies/voorstel from case context | Procest |
| 2 | Adviseur(s) | Provide internal advice on the voorstel | Procest |
| 3 | Parafeerder(s) | Paraferen the voorstel (sequential or parallel) | Procest |
| 4 | Manager/Afdelingshoofd | Accordeert the voorstel | Procest |
| 5 | Portefeuillehouder (wethouder) | Accordeert the voorstel | Procest |
| 6 | Secretariaat/Agendacommissie | Reviews quality, places on agenda | Procest + RIS |
| 7 | BMO/Kwaliteitstoets | Technical and legal quality check | Procest |
| 8 | College B&W | Treats voorstel (hamerstuk or bespreekstuk) | RIS (iBabs/NotuBiz) |
| 9 | Besluitenlijst | Decision recorded and published | RIS -> Procest |
| 10 | Archivering | Besluit linked back to case and archived | Procest |

**Key principle**: Steps 1-7 are ambtelijk (in Procest). Steps 8-9 are bestuurlijk (in the RIS). Step 10 bridges back.

### OpenRegister Schema Model

```
voorstel:
  case: reference           # -> case
  type: enum                # dt_advies | collegeadvies | raadsvoorstel
  onderwerp: string         # from case title
  steller: string           # user UID who created
  afdeling: string          # department
  portefeuillehouder: string # wethouder UID
  status: enum              # concept | in_parafering | ter_accordering | geaccordeerd | aangeboden | besloten | gearchiveerd | teruggestuurd
  parafeerroute: reference  # -> parafeerroute
  currentStep: integer      # current step number in route
  document: string          # Nextcloud file ID for the voorstel document
  bijlagen: array           # Nextcloud file IDs for attachments
  behandeling: enum         # hamerstuk | bespreekstuk
  createdAt: datetime
  updatedAt: datetime

parafeerroute:
  name: string              # "Collegeadvies - Omgevingsvergunning"
  caseType: reference       # -> caseType (optional, for default route)
  voorstelType: enum        # dt_advies | collegeadvies | raadsvoorstel
  steps: array              # ordered list of parafeerstap

parafeerstap:
  order: integer            # 1, 2, 3...
  type: enum                # advies | parafering | accordering
  actor: string             # user UID or role name
  actorType: enum           # user | group | role
  parallel: boolean         # if true, all actors in this step must complete
  parallelActors: array     # list of user UIDs for parallel parafering
  completionRule: enum      # all | any (for parallel: all must complete, or any one)
  mandatory: boolean        # if false, step can be skipped

parafeeractie:
  voorstel: reference       # -> voorstel
  step: integer             # step number
  actor: string             # user UID who performed action
  actorType: enum           # user | delegate
  onBehalfOf: string        # user UID if acting on behalf of someone
  action: enum              # parafered | returned | advised | skipped
  comment: string           # optional comment
  advice: string            # for advisory steps
  timestamp: datetime
  mandate: string           # mandate reference if acting on behalf
```

## Requirements

---

### REQ-BW-01: Voorstel Creation from Case

The system MUST support creating a B&W-voorstel (college proposal) from within a case context.

**Feature tier**: V1


#### Scenario BW-01a: Create college voorstel

- GIVEN a case "Bestemmingsplan Centrum" at status "Besluitvorming"
- WHEN the steller clicks "Nieuw B&W-voorstel" in the case dashboard
- THEN the system MUST create a voorstel object linked to the case in OpenRegister
- AND the voorstel MUST include: onderwerp (from case title), steller (current user), afdeling (from case type config), portefeuillehouder (from case type config)
- AND a document template "Collegeadvies" MUST be generated via Docudesk with case data pre-filled
- AND the case documents MUST be available as bijlagen to the voorstel

#### Scenario BW-01b: Voorstel types

- GIVEN voorstel types: "DT-advies" (directieteam), "Collegeadvies", "Raadsvoorstel"
- WHEN the steller creates a new voorstel
- THEN the steller MUST select the voorstel type from a dropdown
- AND the parafeerroute MUST be loaded from the case type configuration for that voorstel type
- AND the selected type MUST determine which document template is used

#### Scenario BW-01c: Voorstel from case dashboard panel

- GIVEN a case dashboard with a "B&W Voorstellen" panel
- WHEN the panel is empty (no voorstellen yet)
- THEN the panel MUST show: "Geen voorstellen" with a "Nieuw voorstel" button
- AND when a voorstel exists, it MUST show: type, status, current parafeeerstap, steller

#### Scenario BW-01d: Multiple voorstellen per case

- GIVEN a case with an existing "DT-advies" voorstel (status: besloten)
- WHEN the steller creates a new "Collegeadvies" voorstel
- THEN both voorstellen MUST be visible in the case dashboard panel
- AND the history of the DT-advies MUST remain accessible

#### Scenario BW-01e: Pre-fill voorstel from case data

- GIVEN a case with properties: bouwkosten, locatie, aanvrager
- WHEN creating a collegeadvies voorstel
- THEN the Docudesk template MUST pre-fill: onderwerp, zaaknummer, locatie, aanvrager, bouwkosten
- AND the steller MUST be able to edit the generated document before submitting for parafering

---

### REQ-BW-02: Configurable Parafeerroute

The system MUST support configurable parafeerroutes per case type and voorstel type. The route defines who must paraferen/accorderen and in what order.

**Feature tier**: V1


#### Scenario BW-02a: Sequential parafering

- GIVEN a parafeerroute for "Collegeadvies" on case type "Omgevingsvergunning":
  1. Adviseur vakinhoud (advisory)
  2. Juridisch adviseur (advisory)
  3. Teamleider (parafering)
  4. Afdelingshoofd (parafering)
  5. Portefeuillehouder (accordering)
- WHEN the steller submits the voorstel for parafering
- THEN the system MUST route to step 1 first
- AND each step MUST complete before the next step is activated
- AND each actor MUST receive a Nextcloud notification and a task in their "Mijn taken" list

#### Scenario BW-02b: Parallel parafering

- GIVEN a parafeerroute with step 3 configured as parallel: [Teamleider A, Teamleider B]
- AND completionRule = "all"
- WHEN step 3 is reached
- THEN both Teamleider A and Teamleider B MUST receive the voorstel simultaneously
- AND the step completes when ALL parallel actors have parafered
- AND the voorstel status MUST show "Wacht op 2 parafen" until both complete

#### Scenario BW-02c: Override parafeerroute

- GIVEN the standard route requires 5 steps
- AND an authorized manager wants to skip the vakinhoudelijk advies step
- WHEN the manager removes step 1 from the route for this specific voorstel
- THEN the system MUST allow the modification
- AND the audit trail MUST record: "Parafeerroute aangepast: stap 'Adviseur vakinhoud' overgeslagen door [manager], reden: [text]"
- AND a reason MUST be mandatory when skipping steps

#### Scenario BW-02d: Add ad-hoc step

- GIVEN a voorstel at step 2 of 5
- WHEN the steller or manager adds an ad-hoc advisory step "Financieel adviseur" between step 2 and 3
- THEN the route MUST be adjusted: steps 3-5 become 4-6, new step 3 is the ad-hoc step
- AND the audit trail MUST record: "Stap toegevoegd: 'Financieel adviseur' door [user]"

#### Scenario BW-02e: Admin route configuration

- GIVEN the beheerder opens Procest admin settings
- WHEN navigating to "Parafeerroutes" configuration
- THEN the beheerder MUST be able to:
  - Create a new route with named steps
  - Assign each step a type (advies/parafering/accordering), actor type (user/group/role), and parallel flag
  - Link the route to a case type and voorstel type
  - Set a route as the default for a case type

---

### REQ-BW-03: Parafering Actions

Each actor in the parafeerroute MUST be able to perform specific actions on the voorstel.

**Feature tier**: V1


#### Scenario BW-03a: Paraferen (approve)

- GIVEN a voorstel at step "Teamleider" assigned to "Jan de Vries"
- WHEN Jan clicks "Paraferen" in his task or in the voorstel detail view
- THEN the system MUST record a parafeeractie: actor=Jan, action=parafered, timestamp=now
- AND the voorstel MUST advance to the next step
- AND Jan MUST NOT be able to paraferen again on this voorstel
- AND a notification MUST be sent to the next actor in the route

#### Scenario BW-03b: Return with comments (terugsturen)

- GIVEN a voorstel at step "Afdelingshoofd"
- WHEN the afdelingshoofd clicks "Terugsturen" with comment "Financiele paragraaf ontbreekt"
- THEN the voorstel MUST be returned to the steller (status: teruggestuurd)
- AND the comment MUST be visible to the steller in the voorstel detail
- AND the audit trail MUST record the return with reason
- AND the steller MUST be notified: "Voorstel teruggestuurd door [afdelingshoofd]: Financiele paragraaf ontbreekt"
- AND the steller MUST be able to edit the document and resubmit (resumes from the returning step)

#### Scenario BW-03c: Adviseren (non-binding opinion)

- GIVEN a voorstel at an advisory step (not parafering)
- WHEN the adviseur submits advice: "Akkoord, mits bouwkosten worden gecontroleerd"
- THEN the advice MUST be attached to the voorstel as a parafeeractie with action=advised
- AND the voorstel MUST advance to the next step (advice is non-blocking)
- AND the steller and subsequent parafeerders MUST be able to see the advice in the voorstel detail

#### Scenario BW-03d: Paraferen namens (on behalf of)

- GIVEN portefeuillehouder wethouder Van Dam is unavailable
- AND secretaresse Bakker has mandate to paraferen namens Van Dam (configured in admin settings)
- WHEN Bakker opens the voorstel task
- THEN Bakker MUST see an option "Paraferen namens Van Dam"
- AND the audit trail MUST record: "Geparafeerd door Bakker namens Van Dam (mandaat: [reference])"

#### Scenario BW-03e: View voorstel document during parafering

- GIVEN a parafeerder receives a voorstel task
- WHEN opening the voorstel detail view
- THEN the voorstel document MUST be viewable inline (PDF preview or document viewer)
- AND all bijlagen MUST be listed and downloadable
- AND previous advice from earlier steps MUST be visible
- AND the parafering history MUST show which steps are completed

---

### REQ-BW-04: Mobile Parafering

The system MUST support parafering from mobile devices (tablets, smartphones) for bestuurders who are frequently on the move.

**Feature tier**: V2


#### Scenario BW-04a: Paraferen on tablet

- GIVEN wethouder Van Dam viewing pending voorstellen on a tablet
- WHEN Van Dam opens voorstel "Bestemmingsplan Centrum"
- THEN the voorstel document and bijlagen MUST be readable on the tablet
- AND "Paraferen" and "Terugsturen" buttons MUST be accessible
- AND the UI MUST be responsive (no pinch-to-zoom required for core actions)
- AND touch targets MUST be at least 44x44px per WCAG AA

#### Scenario BW-04b: Offline document access

- GIVEN a wethouder preparing for a vergadering without reliable internet
- WHEN the wethouder opens the Nextcloud mobile app
- THEN voorstel documents that were previously viewed MUST be available offline (Nextcloud Files offline sync)
- AND parafering actions MUST queue and sync when connectivity returns

#### Scenario BW-04c: Push notification for pending parafering

- GIVEN a new voorstel awaiting Van Dam's parafering
- WHEN the voorstel reaches Van Dam's step
- THEN a push notification MUST be sent via the Nextcloud mobile app: "Nieuw voorstel ter parafering: [onderwerp]"
- AND tapping the notification MUST open the voorstel detail

---

### REQ-BW-05: RIS Connector (iBabs/NotuBiz)

The system MUST support pushing approved voorstellen to the external RIS for bestuurlijke behandeling, and receiving besluiten back.

**Feature tier**: V2


#### Scenario BW-05a: Push voorstel to iBabs

- GIVEN a voorstel that has completed all ambtelijke parafering steps (status: geaccordeerd)
- AND the secretariaat marks it for agendering with behandeling type (hamerstuk/bespreekstuk)
- WHEN the secretariaat clicks "Aanbieden aan iBabs"
- THEN the system MUST push via iBabs API: voorstel document, bijlagen, metadata (onderwerp, portefeuillehouder, hamerstuk/bespreekstuk)
- AND the voorstel status MUST change to "Aangeboden aan college"
- AND the push status MUST be tracked: "Verstuurd", "Ontvangen", "Fout"

#### Scenario BW-05b: Receive besluit from iBabs

- GIVEN a voorstel treated in the college vergadering
- AND the besluit is recorded in iBabs
- WHEN the besluit is synced back to Procest (via API polling or webhook through OpenConnector)
- THEN the system MUST create a Besluit object linked to the case via the BRC controller
- AND the case timeline MUST show: "College besluit: [besluit tekst]"
- AND the voorstel status MUST change to "Besloten"
- AND the besluit document from iBabs MUST be stored in Nextcloud Files linked to the case

#### Scenario BW-05c: NotuBiz connector

- GIVEN a municipality using NotuBiz instead of iBabs
- WHEN the connector is configured for NotuBiz in OpenConnector
- THEN the same push/receive flow MUST work via NotuBiz API or ZIP(XML+PDF) exchange
- AND the system MUST support both iBabs and NotuBiz as pluggable RIS adapters

#### Scenario BW-05d: RIS connector not configured

- GIVEN no RIS connector is configured
- WHEN the secretariaat views the voorstel
- THEN the "Aanbieden aan RIS" button MUST be hidden
- AND a manual "Markeer als besloten" button MUST allow recording the besluit without a RIS

---

### REQ-BW-06: Parafering Audit Trail

The system MUST maintain an immutable audit trail of all parafering actions. This is a legal requirement -- the trail must be reconstructable for accountability and Archiefwet compliance.

**Feature tier**: V1


#### Scenario BW-06a: Complete audit trail

- GIVEN a voorstel that has passed through 5 parafering steps
- WHEN an auditor reviews the voorstel
- THEN the audit trail MUST show for each step: step number, step type (advies/parafering/accordering), actor, action (parafered/returned/advised/skipped), timestamp, comments
- AND no entries MAY be deleted or modified after recording (immutable)
- AND the trail MUST be exportable as PDF for archival

#### Scenario BW-06b: Route modification audit

- GIVEN a parafeerroute was modified (step skipped or added)
- THEN the audit trail MUST include route modification events: who modified, what changed, reason provided
- AND the original route definition MUST be preserved alongside the modified version

#### Scenario BW-06c: Delegation audit

- GIVEN parafering was performed by a delegate (namens)
- THEN the audit trail MUST clearly distinguish: "Geparafeerd door [delegate] namens [principal] op basis van mandaat [reference]"
- AND both the delegate and principal MUST be searchable in audit queries

---

### REQ-BW-07: Parafering Dashboard

The system MUST provide an overview of all active voorstellen and their parafering status.

**Feature tier**: V1


#### Scenario BW-07a: Secretariaat overview

- GIVEN 8 active voorstellen in various stages of parafering
- WHEN the secretariaat views the parafering dashboard
- THEN each voorstel MUST show: onderwerp, current step, waiting actor, days in current step, overall progress (step 3/5)
- AND voorstellen overdue on any step (waiting > configured threshold) MUST be highlighted in orange/red
- AND the secretariaat MUST be able to send reminders to actors who have not yet parafered

#### Scenario BW-07b: Personal parafering inbox

- GIVEN wethouder Van Dam has 3 voorstellen awaiting his parafering
- WHEN Van Dam opens his parafering inbox (in My Work or as separate view)
- THEN the 3 voorstellen MUST be listed with: onderwerp, case reference, steller, waiting since
- AND each item MUST be actionable directly (paraferen/terugsturen without opening full detail)

#### Scenario BW-07c: Pipeline visualization

- GIVEN 12 voorstellen in the parafering pipeline
- WHEN the secretariaat views the pipeline
- THEN a Kanban-style board MUST show columns per parafering phase: Concept, In parafering, Ter accordering, Geaccordeerd, Aangeboden aan college, Besloten
- AND each voorstel MUST be a card showing: onderwerp, steller, days in phase

#### Scenario BW-07d: Send reminder

- GIVEN a voorstel has been waiting at step "Afdelingshoofd" for 5 days (threshold: 3 days)
- WHEN the secretariaat clicks "Herinnering sturen"
- THEN a Nextcloud notification MUST be sent to the afdelingshoofd: "Voorstel '[onderwerp]' wacht op uw parafering (5 dagen)"
- AND the reminder MUST be logged in the audit trail

---

### REQ-BW-08: Voorstel Detail View

The system MUST provide a dedicated detail view for voorstellen, showing the document, parafering progress, and actions.

**Feature tier**: V1


#### Scenario BW-08a: View voorstel detail

- GIVEN a voorstel "Collegeadvies Bestemmingsplan Centrum"
- WHEN any authorized user opens the voorstel detail
- THEN the view MUST show:
  - Header: onderwerp, type, steller, afdeling, status
  - Document viewer: inline preview of the voorstel document
  - Bijlagen: list of attached documents
  - Parafering progress: visual step indicator showing completed/current/future steps
  - Action history: all parafeeracties with timestamps, actors, comments
  - Case reference: link back to the parent case

#### Scenario BW-08b: Action buttons per role

- GIVEN the current user is the active parafeerder at the current step
- THEN the voorstel detail MUST show action buttons: "Paraferen", "Terugsturen"
- AND if the step type is "advies", the button MUST be "Adviseren" instead of "Paraferen"
- AND if the user is NOT the active actor, action buttons MUST be hidden

#### Scenario BW-08c: Progress timeline

- GIVEN a voorstel with 5 steps where steps 1-3 are completed, step 4 is active, step 5 is pending
- THEN the progress indicator MUST show:
  - Steps 1-3: green checkmark with actor name and date
  - Step 4: blue active indicator with actor name and "Wachtend"
  - Step 5: grey pending indicator with actor name

---

### REQ-BW-09: Besluit Registration

When a besluit is received (from RIS or manually), the system MUST create a formal besluit record linked to the case via the ZGW Besluiten API pattern.

**Feature tier**: V1


#### Scenario BW-09a: Manual besluit registration

- GIVEN a voorstel has been treated by the college (outside Procest)
- WHEN the secretariaat clicks "Besluit registreren" and enters: besluit tekst, ingangsdatum, besluittype
- THEN a besluit object MUST be created via the BRC controller pattern
- AND the besluit MUST be linked to the case (zaak-besluit relation)
- AND the case activity timeline MUST show: "Besluit vastgesteld: [tekst]"

#### Scenario BW-09b: Besluit with documents

- GIVEN a besluit is being registered
- WHEN the secretariaat attaches the besluitbrief and besluitenlijst
- THEN the documents MUST be linked as besluitinformatieobjecten (via `BrcController`)
- AND the documents MUST be stored in Nextcloud Files under the case folder

#### Scenario BW-09c: Withdraw besluit

- GIVEN a besluit has been registered but needs to be withdrawn
- WHEN the secretariaat clicks "Intrekken" with reason "Ingetrokken door overheid"
- THEN the besluit vervaldatum MUST be set to today
- AND the vervalreden MUST be recorded
- AND the case timeline MUST show: "Besluit ingetrokken: [reden]"

---

### REQ-BW-10: Archiving

Completed voorstellen and besluiten MUST be archived according to the Archiefwet requirements.

**Feature tier**: V1


#### Scenario BW-10a: Archive voorstel after besluit

- GIVEN a voorstel has status "Besloten" with a linked besluit
- WHEN the archiving process runs
- THEN the voorstel document, all bijlagen, the audit trail, and the besluit document MUST be packaged
- AND the package MUST be stored in the case's archive folder in Nextcloud Files
- AND the voorstel status MUST change to "Gearchiveerd"

#### Scenario BW-10b: Archive retention metadata

- GIVEN an archived voorstel
- THEN the archive record MUST include: bewaarplaats (Nextcloud Files path), bewaartermijn (from case type config), vernietigingsdatum (calculated from bewaar termijn)
- AND the metadata MUST be queryable for future destruction scheduling

## Dependencies

- **Case Management spec** (`../case-management/spec.md`): Voorstellen originate from cases.
- **Case Dashboard View spec** (`../case-dashboard-view/spec.md`): Voorstel panel on case detail.
- **Roles & Decisions spec** (`../roles-decisions/spec.md`): Besluiten are created when the college decides.
- **Task Management spec** (`../task-management/spec.md`): Parafering steps create tasks for actors.
- **OpenRegister**: Voorstellen, parafeerroutes, parafeeracties stored as OpenRegister objects.
- **OpenConnector**: iBabs API, NotuBiz API adapters for RIS integration.
- **Docudesk**: Document templates for collegeadvies, raadsvoorstel.
- **BrcController**: ZGW Besluiten API pattern for besluit registration (`lib/Controller/BrcController.php`).
- **NotificatieService**: Nextcloud notifications for parafering tasks (`lib/Service/NotificatieService.php`).

### Current Implementation Status

**Not yet implemented.** No parafering, voorstel, or B&W decision-related code exists in the Procest codebase. There are no schemas for voorstel, parafeerroute, or parafeeractie in `procest_register.json`. No Vue components for parafering workflows exist.

**Foundation available:**
- Task management infrastructure (`src/views/tasks/`, `src/services/taskApi.js`, `src/utils/taskLifecycle.js`) provides a model for parafering steps (each step could be modeled as a task with custom type "parafering").
- The `decision` schema exists in `SettingsService::SLUG_TO_CONFIG_KEY` (config key `decision_schema`), providing a foundation for recording besluiten.
- The `decisionType` schema exists for typing decisions.
- ZGW Besluiten API controller (`lib/Controller/BrcController.php`) handles besluit CRUD via ZGW API endpoints, including cross-register OIO sync and cascade delete.
- Activity timeline component (`src/views/cases/components/ActivityTimeline.vue`) could display parafering events.
- Nextcloud notification infrastructure is available via the `NotificatieService` (`lib/Service/NotificatieService.php`).
- `CnDetailCard` component pattern for the voorstel panel on the case dashboard.
- Case detail view (`CaseDetail.vue`) provides the mounting point for the B&W voorstellen panel.

**Partial implementations:** The `BrcController` and decision schemas provide the data model foundation for step 9-10 (besluit registration and archiving).

### Standards & References

- **BPMN 2.0**: Process modeling standard for sequential/parallel parafeerroutes.
- **CMMN 1.1**: HumanTask concept maps to parafering steps. Each step is a human task in a case plan model.
- **ZGW Besluiten API (VNG)**: For recording formal besluiten (decisions) linked to cases. Procest's `BrcController` implements this standard.
- **Awb (Algemene wet bestuursrecht)**: Legal framework for administrative decision-making.
- **iBabs API**: Commercial API for raadsinformatiesysteem (council information system). REST-based with JSON payloads.
- **NotuBiz API**: Alternative RIS platform API. Supports ZIP(XML+PDF) exchange format.
- **GEMMA**: B&W besluitvormingsproces is a standard reference process in GEMMA zaakgericht werken.
- **Archiefwet**: Legal requirements for archiving besluiten and voorstel documents.
- **BIO**: Security requirements for handling voorstellen containing confidential information.

### Specificity Assessment

This spec is well-structured with a clear 10-step process model, defined OpenRegister schemas, and feature tier separation (V1/V2). The scenarios are detailed with concrete actor/action/system descriptions.

**Strengths:** Clear process model with 10 steps, OpenRegister schema definitions for voorstel/parafeerroute/parafeeractie, concrete delegation scenario (namens), sequential and parallel parafering, admin route configuration, audit trail requirements, RIS connector patterns.

**Resolved ambiguities:**
- Parafeerroutes are stored as OpenRegister objects (not n8n workflows), enabling version tracking and admin UI.
- The parafering dashboard is a separate navigation item (not a dashboard tab), with both secretariaat overview and personal inbox views.
- Unavailable actors without delegates trigger escalation to the secretariaat after a configurable waiting period.
- iBabs integration uses REST API via OpenConnector; NotuBiz supports both API and ZIP exchange.
- Mandate/delegation is configured in admin settings and recorded in the audit trail with mandate reference.
- Parallel parafering supports both "all" and "any" completion rules.
