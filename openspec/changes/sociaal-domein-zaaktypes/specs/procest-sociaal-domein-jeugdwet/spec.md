# Spec: Jeugdwet (Youth support) zaaktype family

**Tier:** sociaal-domein  
**Statutory basis:** Jeugdwet 2015, artikel 2.3 (jeugdhulpplicht), 6.1.2 (gezinsplan), 7.3 (data processing)  
**Zaaktype identifier:** `jeugdwet-melding`  
**Processing deadline:** 4 weeks (gezinsplan) + 2 weeks (decision) = 6 weeks total

## Scope

Jeugdwet (Wet op de jeugdhulp) is the statutory framework for municipal support of children (0–17) and their families in crisis or requiring intervention. A Jeugdwet zaak typically involves:

- A referral from school, healthcare provider, or family self-referral (melding)
- Multi-professional assessment and family-centered planning (gezinsplan)
- Coordination with multiple services (school, GGD, specialized youth care providers, etc.) via Multi-Disciplinary Overleg (MDO)
- Ongoing support with scheduled re-evaluations and possible extensions

Unlike WMO (adult-focused, individual assessment) or Participatiewet (income/work-focused), Jeugdwet is fundamentally **family-centered** and **multi-agency**, often involving trauma, behavioral challenges, family breakdown, and the need for explicit family consent and coordination.

This spec covers zaaktype definition, mandatory entities (Gezinsplan, MdoOverleg, Toestemming), status flow, and integration with AVG/consent infrastructure.

## Entities

### JeugdwetZaak

Main case entity. Inherits from procest `case` with youth/family-specific fields.

| Field | Type | Required | Description |
|---|---|---|---|
| zaaktype | reference | Y | Fixed: `jeugdwet-melding` |
| gezinId | string | Y | Unique family identifier (may span multiple jeugdwet zaakken if siblings) |
| jeugdigeBsn | string | Y | Child's Dutch BSN |
| jeugdigeLeeftijd | integer | Y | Child's age in years at melding (0–17) |
| verzoekKanaal | enum | Y | Referral source: `huisarts`, `school`, `maatschappelijk-werker`, `politie`, `justitie`, `gezinsraad`, `zelf-melding` |
| verzoekDatum | date | Y | Date referral received |
| verwijzer | object | Y | Referrer details: `type` (huisarts, schooldirecteur, etc.), `agbCode` or `organisatie`, `naam` |
| ondersteuningsvraag | string | Y | Chief complaint/concern (free text, may span multiple domains: gedrag, school, family conflict, etc.) |
| wijkteam | reference | Y | Assigned jeugdteam |
| behandelaarId | string | Y | Primary jeugdconsulent |
| status | enum | Y | Lifecycle: `melding` → `gezinsplan-opstellen` → `gezinsplan-gereed` → `ondersteuning-gestart` → `ondersteuning-loopt` → `evaluatie` → `afgesloten` (or `verlenging-aangevraagd`) |
| avgClassificatie | object (AvgClassificatie) | Y | Mandatory classification (likely includes medisch, gezinssituatie; may include justitieel if abuse/neglect context) |
| gezinsplanId | reference | N | Link to current Gezinsplan |
| mdoOverlegIds | array (references) | N | Links to all MdoOverleg records for this zaak |
| ondertoezichtstellingActief | boolean | N | Whether a formal onder-toezicht-stelling (OTS, court order) is in effect |
| verlengingHistorie | array | N | Array of previous Gezinsplan IDs if this zaak has been extended |

### Gezinsplan

Family-centered support plan, requires explicit agreement from all household members.

| Field | Type | Required | Description |
|---|---|---|---|
| zaakId | reference | Y | Parent JeugdwetZaak |
| opgesteldDoor | string | Y | Jeugdconsulent who drafted the plan |
| opgesteldDatum | date | Y | Plan creation date |
| gezinsleden | array | Y | Family members: `rol` (moeder, vader, jeugdige, stiefouder, etc.), `bsn`, `akkoord` (boolean), `akkoordDatum`, `leeftijdToestemmingsvereiste` (true if 16+) |
| doelen | array | Y | Family goals (free text, 2–5 goals): "Verbeteren communicatie ouders-kind", "Schoolresultaten stabiliseren", etc. |
| inzetTrajecten | array | Y | Interventions: `soort` (ambulante-jeugdhulp, intensieve-gezinsbegeleiding, etc.), `aanbieder`, `startDatum`, `duurMaanden` |
| evaluatieMomenten | array | N | Scheduled evaluation dates (typically 3–4 per trajectory) |
| verlengingMogelijk | boolean | Y | Can plan be extended if goals not achieved by end date? |
| verlengingVan | reference | N | If this is a verlengingplan, ID of the previous plan (link in verlengingHistorie) |

### MdoOverleg

Multi-Disciplinary Overleg (MDO) — formal conference where school, healthcare, social work, and family coordinate on a single case.

| Field | Type | Required | Description |
|---|---|---|---|
| zaakIds | array | Y | One or more Jeugdwet zaakken discussed (siblings often covered in one MDO) |
| overlegDatum | datetime | Y | Meeting date and time |
| deelnemers | array | Y | Attendees: `rol` (jeugdconsulent, jeugdarts, schoolmaatschappelijk-werker, etc.), `medewerkerId` or `naam`, `organisatie`, `toestemmingDeelnameDoorClient` (boolean) |
| agenda | array | N | Meeting topics (agenda items) |
| verslag | reference | N | Nextcloud file ID of meeting minutes (verslag) |
| toestemmingenGeregistreerd | boolean | Y | Were family consents for external participation recorded? |
| gedeeldeGegevens | enum | Y | Data shared in minutes: `alle-gegevens` (full identified), `alleen-anonimiseerde-samenvatting` (anonymized) |

### Toestemming (shared from cross-cutting spec, reused here)

Explicit permission from a parent/guardian for their child's data to be shared with named external parties during the zaak.

| Field | Type | Required | Description |
|---|---|---|---|
| zaakId | reference | Y | JeugdwetZaak |
| verleendDoorBsn | string | Y | Parent/guardian who granted permission (BSN of the adult consenter) |
| verleendDatum | date | Y | Date permission given |
| geldigTot | date | N | Expiry date for this permission |
| tePartijen | array | Y | External parties who may receive data: "Jeugdzorg West", "Basisschool De Vlinder", etc. |
| tegegevens | array | Y | Specific data subsets approved: "gezinsplan-doelen", "evaluatie-momenten", "MDO-samenvatting" |
| tedoel | string | Y | Purpose: "Afstemming jeugdhulp en schoolsituatie" |
| intrekkingMogelijk | boolean | Y | Can the permission be revoked? |
| ingetrokken | boolean | N | Has it been revoked? |

## Requirements

### REQ-JW-001: Jeugdwet zaaktype definition and family-centered status lifecycle

The system MUST support a `jeugdwet-melding` zaaktype with family-centered status flow.

**GIVEN** a jeugdconsulent creates a new Jeugdwet case  
**WHEN** zaaktype is set to `jeugdwet-melding`  
**THEN** the system MUST automatically:
- Initialize status to `melding`
- Require `avgClassificatie` (likely with `gezinssituatie` category)
- Generate a case identifier in format `zaak-{year}-jeugd-{5-digit-sequence}`
- Pre-create an empty Gezinsplan object (to be filled in by consulent)

### REQ-JW-002: Gezinsplan creation and family consent workflow

The system MUST support gezinsplan drafting and explicit family agreement.

**GIVEN** a jeugdconsulent completes a family assessment and drafts a gezinsplan  
**WHEN** the plan is saved  
**THEN** the system MUST:
- Set JeugdwetZaak status to `gezinsplan-gereed`
- Create consent-recording tasks for all gezinsleden (and for jeugdige if 16+)
- Block plan approval until all required signatures are recorded

**GIVEN** a gezinslid is age 16 or older  
**WHEN** a gezinsplan is drafted  
**THEN** that individual's `akkoord` MUST be explicitly recorded; consent cannot be assumed via guardian signature alone.

### REQ-JW-003: Mandatory AVG classification with special attention to family/behavioral data

Every Jeugdwet case concerns minors; classification MUST explicitly flag family and behavioral data.

**GIVEN** a jeugdconsulent creates a JeugdwetZaak  
**WHEN** the zaak is saved without avgClassificatie  
**THEN** the system MUST reject the save and require classification.

**GIVEN** the case involves behavioral concerns or family conflict  
**WHEN** classification is completed  
**THEN** `categorieen` MUST include `gezinssituatie` and the bewaarTermijn MUST be set to 20 jaren (Jeugdwet retention standard) or longer.

### REQ-JW-004: Multi-Disciplinary Overleg (MDO) with explicit external-party consent

Jeugdwet zaakken often require coordination across school, healthcare, and social services. External participants (outside gemeente) MUST have explicit family consent.

**GIVEN** a jeugdconsulent schedules an MDO meeting with a schoolmaatschappelijk werker from an externe organisatie  
**WHEN** the MDO is created  
**THEN** the system MUST check for a toestemming record that explicitly permits data-sharing with that school.

**GIVEN** toestemming is not found  
**WHEN** the MDO meeting proceeds  
**THEN** the system MUST:
- Display a warning to the consulent
- Log that the meeting happened without recorded consent
- Automatically anonymize any data shared in the MDO verslag (names, specific details → roles only, functional summaries)

**GIVEN** toestemming IS found and in effect  
**WHEN** the MDO verslag is finalized  
**THEN** the system MUST log which specific data (from `tegegevens`) was shared and on what legal basis.

### REQ-JW-005: Gezinsplan evaluation and extension workflow

Jeugdwet support trajectories often require re-planning if initial goals are not met.

**GIVEN** a gezinsplan is due for evaluation at a scheduled evaluatieMoment  
**WHEN** the date approaches (within 14 days)  
**THEN** the system MUST create a task for the consulent to conduct evaluatie (contact family, assess progress on goals).

**GIVEN** evaluation shows insufficient progress and verlengingMogelijk=true  
**WHEN** the consulent decides to extend support  
**THEN** the system MUST:
- Create a new Gezinsplan with the same `zaakId` (updating gezinsplanId)
- Link the new plan to the old via `verlengingVan`
- Add the old plan ID to JeugdwetZaak.verlengingHistorie
- Reset all gezinsleden consent status to `akkoord=false` (re-consent required)
- Notify all gezinsleden that a new plan consent round is underway

### REQ-JW-006: Access control with special FG/child-safety overrides

Jeugdwet cases can involve child safety concerns; certain overrides may be needed (balance privacy with protection duty).

**GIVEN** a jeugdconsulent opens a JeugdwetZaak  
**WHEN** the case is queried  
**THEN** only the jeugdteam + assigned consulent may see case content.

**GIVEN** a child-safeguarding concern triggers an escalation to child-protection authorities  
**WHEN** data-sharing is needed with Veilig Thuis (child maltreatment reporting body)  
**THEN** the system MUST:
- Log the override reason (e.g., "verdacht kindermishandeling per art. 47c Jeugdwet")
- Proceed with unannonymized sharing (child safety takes precedence)
- Create an audit log entry noting the exceptional disclosure

### REQ-JW-007: Automatic anonymization of MDO minutes when consent is not recorded

If an MDO meeting involves external parties but family consent is not recorded, the verslag MUST be anonymized.

**GIVEN** an MDO involves a GGD jeugdarts and no toestemming is found  
**WHEN** the MDO verslag is drafted  
**THEN** the system MUST remove:
- Child's name (replace with "jeugdige, leeftijd X")
- Family names (replace with "ouders", "familielid A", "familielid B")
- Specific diagnoses/clinical details (replace with functional summaries: "gedragsproblemen", "schoolmoeilijkheden")
- Named zorgaanbieders (replace with "huidge provider" or "proposed provider")

**GIVEN** the jeugdige's BSN or other identifying data exists in the minutes draft  
**WHEN** anonimisering is applied  
**THEN** the system MUST invoke `pii-detection-masking` from openregister to ensure consistent redaction.

### REQ-JW-008: Statutory retention (20 years) and destruction proposal

All Jeugdwet cases MUST be retained for 20 years post-closure (longer than WMO due to potential developmental/lifelong impacts).

**GIVEN** a JeugdwetZaak is closed on 2026-03-15  
**WHEN** vernietigingsDatum is calculated  
**THEN** it MUST be set to 2046-03-15 (exactly 20 years later).

**GIVEN** the current date is within 30 days of vernietigingsDatum  
**WHEN** a batch job runs  
**THEN** the system MUST generate a vernietigingsvoorstel for archivaris review.

### REQ-JW-009: Comprehensive audit logging with focus on external-party data access

Every read of Jeugdwet case data MUST be logged; particular attention to reads by externe organisaties.

**GIVEN** a jeugdconsulent from the gemeente opens a JeugdwetZaak  
**WHEN** the case is displayed  
**THEN** the system MUST log: `zaakId`, `medewerkerId`, `organisatie` (gemeente), `tijdstip`, `geraadpleegdeVelden`.

**GIVEN** an externe jeugdzorg provider is given read-access to the gezinsplan (via openconnector or API, under toestemming)  
**WHEN** they access the data  
**THEN** the system MUST log: `zaakId`, `partnerOrganisatie`, `tijdstip`, `geautoriseerdeGegevens` (from toestemming.tegegevens).

### REQ-JW-010: Subject-access-request (burgerrecht) support for jeugdige + parents

Jeugdwet cases may generate subject-access requests from both the child and parents; the system MUST support these workflows.

**GIVEN** a parent files a subject-access request (AVG art. 15) for their child's jeugdwet zaakken  
**WHEN** the FG processes the request  
**THEN** the system MUST be able to generate a report listing:
- All JeugdwetZaakken for that child
- All Gezinsplanen, MdoOverleg records, and Toestemmingen
- Audit log of who accessed the data and when
- All documents attached to the zaak

## Seed data

Three realistic Jeugdwet cases (Dutch context, typical scenarios):

### Case 1: Behavioral issues post-parental divorce
- **Case ID:** zaak-2026-jeugd-00921
- **Child:** Jeugdige X (BSN: 987654321), age 9
- **Family ID:** gezin-04472
- **Referral:** Huisarts verwijzing (gedragsverandering na scheiding)
- **Referrer:** Praktijk Bos & Co (AGB: 01-029384)
- **Chief concern:** "Gedragsveranderingen op school en thuis, agressiviteit, schoolweigering na echtscheiding"
- **Status:** ondersteuning-loopt
- **Gezinsplan:** 
  - Goals: "Verbeteren communicatie ouder-kind", "Schoolresultaten stabiliseren", "Sociale vaardigheden via groepstraining"
  - Inzet: ambulante jeugdhulp (Jeugdzorg West, start 2026-04-01, 6 maanden)
  - Evaluaties: 2026-07-01, 2026-10-01
- **MDO:** One meeting scheduled (school, GGD jeugdarts, consulent)
- **Household:** Two-parent family (split custody); child lives alternating weeks
- **Wijkteam:** jeugdteam-noord
- **AVG classification:** Gezinssituatie + medisch (behavioral assessment), bewaarTermijn 20 jaren

### Case 2: School refusal with depression
- **Case ID:** zaak-2026-jeugd-01847
- **Child:** Jeugdige Y, age 16
- **Family ID:** gezin-05103
- **Referral:** Schoolmaatschappelijk werker (schoolweigering + depressieve symptomen)
- **Chief concern:** "Totale schoolweigering sinds 2 maanden, slaapstoornissen, sociale isolatie"
- **Status:** gezinsplan-gereed (awaiting family signatures)
- **Gezinsplan:** (draft, not yet signed)
  - Goals: "Terugkeer naar school", "Psychische gezondheid verbeteren", "Sociaal netwerk herstellen"
  - Inzet: outreachend jeugdwerk + jeugdpsychiatrisch consult (GGD)
- **Family consent status:** 
  - Moeder: akkoord gegeven
  - Vader: akkoord gegeven
  - Jeugdige (16+): akkoord required, pending
- **Jeugdteam:** jeugdteam-zuid
- **AVG classification:** Medisch + gezinssituatie (mental health, school crisis), bewaarTermijn 20 jaren

### Case 3: Toddler developmental delay (early intervention)
- **Case ID:** zaak-2026-jeugd-02456
- **Child:** Jeugdige Z, age 2
- **Family ID:** gezin-06201
- **Referral:** GGD kinderfysiotherapeut (developmental delay; monitoring during early childhood)
- **Chief concern:** "Vertraagde motorische ontwikkeling, vraag om vroeginterventie en scholing ouders"
- **Status:** ondersteuning-gestart
- **Gezinsplan:**
  - Goals: "Motorische milestone bereiken op leeftijd + 6 maanden", "Ouderschap-ondersteuning"
  - Inzet: fysiotherapie (2x/week bij thuis), pedagogische ondersteuning ouders (maandelijks)
  - Expected duration: 12–18 maanden, review quarterly
- **Household:** Young single parent (moeder), limited mantelzorg
- **Wijkteam:** jeugdteam-west
- **AVG classification:** Medisch (developmental assessment), gezinssituatie (single-parent support), bewaarTermijn 20 jaren

## Integration points

- **docudesk:** Gezinsplan-template (printable family-friendly version), beschikking-template (formal decision letter)
- **openconnector:** iJW berichtenverkeer with jeugdzorg providers; CJG (centrum voor jeugd en gezin) coordination; GGD referrals
- **mydash:** Jeugdteam dashboard (caseload, evaluation-due dates, extension-pending counts)
- **openregister:** Toestemming RBAC (allow/deny read-access to externe partner), retention scheduling, audit-trail

## Design notes

- **Family-centered framing:** Unlike WMO (individual assessment), Jeugdwet always treats the *family system* as the unit of analysis (gezinsleden, gezinsdoelen, family-wide evaluatie).
- **Multi-agency coordination:** MDO support is foundational, not optional. The system tracks external-party participation and consent explicitly.
- **Child agency:** From age 16, the child themselves is a consenting party (not just a subject of the plan), reflecting developmental maturity.
- **Longer retention:** 20-year retention (vs. 15 for WMO) reflects that childhood intervention can affect lifelong outcomes.
- **Lifecycle:** All status transitions and evaluatie scheduling declared as `x-openregister-lifecycle` per ADR-031.

