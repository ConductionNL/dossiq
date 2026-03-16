# B&W Parafering & Besluitvorming Specification

## Purpose

B&W parafering covers the ambtelijk (civil servant) workflow for preparing, reviewing, and approving proposals before they reach the College van B&W for formal decision-making. The bestuurlijk (political) part -- agenda management, vergadering, and besluitenlijst -- is handled by external RIS systems (iBabs, NotuBiz). This spec covers the ambtelijk workflow and the connector to the RIS.

**Tender demand**: Found in 20+ tenders (29% of all, higher among generic zaaksysteem tenders). B&W besluitvorming is the #6 Nice-to-have but weighs heavily in scoring (typically 3-8% of total score, up to 68 points).
**Standards**: BPMN 2.0 (process modeling), ZGW Besluiten API, ZDS (Zaak-Document Services for legacy RIS)
**Feature tier**: V1 (ambtelijk parafering, sequential routing, audit trail), V2 (parallel parafering, mobile parafering, iBabs/NotuBiz connector, vergaderbeheer)

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

## Requirements

---

### REQ-BW-01: Voorstel Creation from Case

**Feature tier**: V1

The system MUST support creating a B&W-voorstel (college proposal) from within a case context.

#### Scenario BW-01a: Create college voorstel

- GIVEN a case "Bestemmingsplan Centrum" at status "Besluitvorming"
- WHEN the steller clicks "Nieuw B&W-voorstel"
- THEN the system MUST create a voorstel object linked to the case
- AND the voorstel MUST include: onderwerp (from case title), steller, afdeling, portefeuillehouder
- AND a document template "Collegeadvies" MUST be generated via Docudesk
- AND the case documents MUST be available as bijlagen to the voorstel

#### Scenario BW-01b: Voorstel types

- GIVEN voorstel types: "DT-advies" (directieteam), "Collegeadvies", "Raadsvoorstel"
- WHEN the steller creates a new voorstel
- THEN the steller MUST select the voorstel type
- AND the parafeerroute MUST be loaded from the case type configuration for that voorstel type

---

### REQ-BW-02: Configurable Parafeerroute

**Feature tier**: V1

The system MUST support configurable parafeerroutes per case type and voorstel type. The route defines who must paraferen/accorderen and in what order.

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
- AND each actor MUST receive a Nextcloud notification and task

#### Scenario BW-02b: Parallel parafering

- GIVEN a parafeerroute with step 3 configured as parallel: [Teamleider A, Teamleider B]
- WHEN step 3 is reached
- THEN both Teamleider A and Teamleider B MUST receive the voorstel simultaneously
- AND the step completes when ALL parallel actors have parafered

#### Scenario BW-02c: Override parafeerroute

- GIVEN the standard route requires 5 steps
- AND an authorized manager wants to skip the vakinhoudelijk advies step
- WHEN the manager removes step 1 from the route for this specific voorstel
- THEN the system MUST allow the modification
- AND the audit trail MUST record: "Parafeerroute aangepast: stap 'Adviseur vakinhoud' overgeslagen door [manager], reden: [text]"

---

### REQ-BW-03: Parafering Actions

**Feature tier**: V1

Each actor in the parafeerroute MUST be able to perform specific actions on the voorstel.

#### Scenario BW-03a: Paraferen (approve)

- GIVEN a voorstel at step "Teamleider" assigned to "Jan de Vries"
- WHEN Jan clicks "Paraferen"
- THEN the system MUST record: actor, timestamp, action "parafered"
- AND the voorstel MUST advance to the next step
- AND Jan MUST NOT be able to paraferen again on this voorstel

#### Scenario BW-03b: Return with comments (terugsturen)

- GIVEN a voorstel at step "Afdelingshoofd"
- WHEN the afdelingshoofd clicks "Terugsturen" with comment "Financiele paragraaf ontbreekt"
- THEN the voorstel MUST be returned to the steller
- AND the comment MUST be visible to the steller
- AND the audit trail MUST record the return with reason
- AND the steller MUST be notified: "Voorstel teruggestuurd door [afdelingshoofd]: Financiele paragraaf ontbreekt"

#### Scenario BW-03c: Adviseren (non-binding opinion)

- GIVEN a voorstel at an advisory step (not parafering)
- WHEN the adviseur submits advice: "Akkoord, mits bouwkosten worden gecontroleerd"
- THEN the advice MUST be attached to the voorstel as annotation
- AND the voorstel MUST advance to the next step (advice is non-blocking)
- AND the steller and subsequent parafeerders MUST be able to see the advice

#### Scenario BW-03d: Paraferen namens (on behalf of)

- GIVEN portefeuillehouder wethouder Van Dam is unavailable
- AND secretaresse Bakker has mandate to paraferen namens Van Dam
- WHEN Bakker parafes the voorstel
- THEN the audit trail MUST record: "Geparafeerd door Bakker namens Van Dam (mandaat)"

---

### REQ-BW-04: Mobile Parafering

**Feature tier**: V2

The system MUST support parafering from mobile devices (tablets, smartphones) for bestuurders who are frequently on the move.

#### Scenario BW-04a: Paraferen on tablet

- GIVEN wethouder Van Dam viewing pending voorstellen on a tablet
- WHEN Van Dam opens voorstel "Bestemmingsplan Centrum"
- THEN the voorstel document and bijlagen MUST be readable on the tablet
- AND "Paraferen" and "Terugsturen" buttons MUST be accessible
- AND the UI MUST be responsive (no pinch-to-zoom required for core actions)

---

### REQ-BW-05: RIS Connector (iBabs/NotuBiz)

**Feature tier**: V2

The system MUST support pushing approved voorstellen to the external RIS for bestuurlijke behandeling, and receiving besluiten back.

#### Scenario BW-05a: Push voorstel to iBabs

- GIVEN a voorstel that has completed all ambtelijke parafering steps
- AND the secretariaat marks it for agendering
- WHEN the secretariaat clicks "Aanbieden aan iBabs"
- THEN the system MUST push via iBabs API: voorstel document, bijlagen, metadata (onderwerp, portefeuillehouder, hamerstuk/bespreekstuk)
- AND the voorstel status MUST change to "Aangeboden aan college"

#### Scenario BW-05b: Receive besluit from iBabs

- GIVEN a voorstel treated in the college vergadering
- AND the besluit is recorded in iBabs
- WHEN the besluit is synced back to Procest (via API polling or webhook)
- THEN the system MUST create a Besluit object linked to the case
- AND the case timeline MUST show: "College besluit: [besluit tekst]"
- AND the voorstel status MUST change to "Besloten"

#### Scenario BW-05c: NotuBiz connector

- GIVEN a municipality using NotuBiz instead of iBabs
- WHEN the connector is configured for NotuBiz
- THEN the same push/receive flow MUST work via NotuBiz API or ZIP(XML+PDF) exchange
- AND the system MUST support both iBabs and NotuBiz as pluggable RIS adapters

---

### REQ-BW-06: Parafering Audit Trail

**Feature tier**: V1

The system MUST maintain an immutable audit trail of all parafering actions. This is a legal requirement -- the trail must be reconstructable for accountability.

#### Scenario BW-06a: Complete audit trail

- GIVEN a voorstel that has passed through 5 parafering steps
- WHEN an auditor reviews the voorstel
- THEN the audit trail MUST show for each step: actor, action (parafered/returned/advised), timestamp, comments
- AND no entries MAY be deleted or modified after recording
- AND the trail MUST be exportable as PDF for archival

---

### REQ-BW-07: Parafering Dashboard

**Feature tier**: V1

The system MUST provide an overview of all active voorstellen and their parafering status.

#### Scenario BW-07a: Secretariaat overview

- GIVEN 8 active voorstellen in various stages of parafering
- WHEN the secretariaat views the parafering dashboard
- THEN each voorstel MUST show: onderwerp, current step, actor, days in current step
- AND voorstellen overdue on any step MUST be highlighted
- AND the secretariaat MUST be able to send reminders to actors who have not yet parafered

## Dependencies

- **Case Management spec** (`../case-management/spec.md`): Voorstellen originate from cases.
- **Roles & Decisions spec** (`../roles-decisions/spec.md`): Besluiten are created when the college decides.
- **Task Management spec** (`../task-management/spec.md`): Parafering steps create tasks for actors.
- **OpenConnector**: iBabs API, NotuBiz API adapters.
- **Docudesk**: Document templates for collegeadvies, raadsvoorstel.
