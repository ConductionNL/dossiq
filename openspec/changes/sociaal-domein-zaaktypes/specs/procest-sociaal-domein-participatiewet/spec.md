# Spec: Participatiewet (Work & Income Support) zaaktype family

**Tier:** sociaal-domein  
**Statutory basis:** Participatiewet 2015, artikel 18 (algemene bijstand), 31–34 (vermogens-/inkomenstoets), artikel 9 (re-integratie)  
**Zaaktype identifier:** `bijstandsaanvraag`  
**Processing deadline:** 2 weeks (toetsing + beschikking)

## Scope

Participatiewet (Participation Act) is the statutory framework for:

1. **Algemene bijstand** (general welfare/income support) — temporary emergency income for unemployed adults without other means
2. **Re-integratie trajecten** (job placement & upskilling) — active labor-market intervention to reduce welfare dependency
3. **Related instruments** — wage subsidies (loonkostensubsidie), training, job coaching, support for self-employment

A Participatiewet zaak differs fundamentally from WMO (medical/aging support) and Jeugdwet (family/youth crisis) by being **income-focused** and **work-focused**. The data is highly sensitive: financial circumstances, employment history, tax records, sometimes debt, criminal history (employment barriers). The client often faces economic hardship and vulnerability to predatory practices.

This spec covers zaaktype definition, mandatory entities (ReIntegratieTraject), the vermogens-/inkomenstoets workflow, status flow, and integration with AVG/retention infrastructure.

## Entities

### ParticipatiewetZaak

Main case entity. Inherits from procest `case` with work-and-income-specific fields.

| Field | Type | Required | Description |
|---|---|---|---|
| zaaktype | reference | Y | Fixed: `bijstandsaanvraag` |
| bsn | string | Y | Applicant's Dutch BSN |
| aanvraagSoort | enum | Y | Type of income support: `algemene-bijstand`, `inburgering-gerelateerde-bijstand`, `bijstand-werkloze-uitkeringontvangers`, `noodvoorziening` |
| aanvraagDatum | date | Y | Date application received |
| ingangsdatumGewenst | date | Y | Requested benefit start date (typically aanvraagDatum or immediately after) |
| leeftijdsgroep | enum | Y | `18-21`, `21-plus-tot-aow`, `aow-leeftijd-plus` |
| huishoudensSituatie | enum | Y | `alleenstaand`, `alleenstaand-met-kinderen`, `paar-zonder-kinderen`, `paar-met-kinderen` |
| vermogensToets | object | Y | Asset test: `uitgevoerd` (boolean), `vermogen` (€ amount), `vermogensvrijstelling` (legal threshold), `boven_vermogensvrijstelling` (boolean) |
| inkomensToets | object | Y | Income test: `uitgevoerd` (boolean), `inkomenPerMaand` (€), `bijstandsnormPerMaand` (statutory standard), `rechtOpBijstand` (boolean = income < norm?) |
| reIntegratieTrajectId | reference | N | Link to ReIntegratieTraject (created only if income test passes) |
| behandelaarId | string | Y | Klantmanager assigned to case |
| status | enum | Y | Lifecycle: `aanvraag-ontvangen` → `toetsing-loopt` → `toetsing-afgerond` → `beschikking-voorbereiding` → `beschikking-gereed` → `bijstand-actief` → `re-integratie-loopt` → `afgesloten` |
| avgClassificatie | object (AvgClassificatie) | Y | Mandatory classification (typically financieel + sometimes medisch/gezinssituatie) |
| reIntegratieTrajectId | reference | N | Link to ReIntegratieTraject (once bijstand is approved + re-integratie deemed necessary) |

### ReIntegratieTraject

Re-integration pathway — the active labor-market intervention to help the client regain employment.

| Field | Type | Required | Description |
|---|---|---|---|
| zaakId | reference | Y | Parent ParticipatiewetZaak |
| klantmanagerId | string | Y | Klantmanager overseeing the trajectory |
| startDatum | date | Y | Date re-integration support begins |
| trajectSoort | enum | Y | Type: `werkfit-maken` (general job readiness), `scholing-specific` (training for named role), `plaatsing-ondersteund` (supported job matching), `zelfstandigheid-bevorderen` (self-employment support) |
| afstandTotArbeidsmarkt | enum | Y | Labor-market distance: `klein` (minimal barriers, recent job loss), `groot` (significant barriers: age, health, skills gap), `zeer-groot` (combination of barriers) |
| instrumenten | array | Y | Interventions deployed: `soort` (loonkostensubsidie, scholing, begeleiding-werkplek, etc.), budget/percentage/duration parameters per instrument |
| samenwerkendePartijen | array | Y | Partner organizations: `partij` (UWV, jobtraining provider, etc.), `rol` (no-risk-polis provider, matching service, etc.) |
| evaluatieMomenten | array | N | Scheduled milestones: quarterly reviews + end-of-trajectory evaluation |
| tegenprestatieVerplicht | boolean | Y | Is the applicant required to perform counter-services (e.g., job-search, training participation)? |
| vrijstellingArbeidsverplichting | string | N | If not tegenprestatieVerplicht: reason (medisch, ouderschap, etc.) |

## Requirements

### REQ-PW-001: Participatiewet zaaktype definition and income-focused status lifecycle

The system MUST support a `bijstandsaanvraag` zaaktype with mandatory financial testing before benefit approval.

**GIVEN** a klantmanager creates a new Participatiewet case  
**WHEN** zaaktype is set to `bijstandsaanvraag`  
**THEN** the system MUST automatically:
- Initialize status to `aanvraag-ontvangen`
- Create placeholder objects for `vermogensToets` and `inkomensToets` (both `uitgevoerd=false`)
- Require `avgClassificatie` (typically financieel category)
- Block progression to beschikking until both toetsen are `uitgevoerd=true`
- Generate a case identifier in format `zaak-{year}-pw-{5-digit-sequence}`

### REQ-PW-002: Mandatory vermogens- and inkomenstoets with automatic disposition

The two-test sequence MUST be completed before any benefit decision. Vermogen above threshold MUST auto-trigger refusal.

**GIVEN** a klantmanager executes the vermogenstoets and records vermogen > vermogensvrijstelling  
**WHEN** the test is saved  
**THEN** the system MUST:
- Set `boven_vermogensvrijstelling=true`
- Auto-transition zaak status to `toetsing-afgerond` (tests complete, but with adverse result)
- Mark zaak as "afwijzingsvoorstel" (refusal recommendation) with pre-filled motivation
- Notify klantmanager that beschikking-drafting must document the refusal

**GIVEN** both vermogens- and inkomenstoets are `uitgevoerd=true` AND vermogen is under threshold AND inkomen is under bijstandsnorm  
**WHEN** zaak is saved  
**THEN** the system MUST:
- Set `rechtOpBijstand=true`
- Auto-transition status to `beschikking-voorbereiding`
- Create a ReIntegratieTraject object (linking the two)

### REQ-PW-003: Automatic re-integratie-trajectory creation when income test passes

If the applicant qualifies for bijstand (income < norm, vermogen < threshold), they are by law required to participate in re-integration.

**GIVEN** inkomenstoets results in `rechtOpBijstand=true`  
**WHEN** the zaak is saved  
**THEN** the system MUST automatically:
- Create a ReIntegratieTraject object with `zaakId` reference
- Set `klantmanagerId` to the case's behandelaar
- Set `startDatum` to ingangsDatum (or shortly after beschikking issuance)
- Require the klantmanager to populate `trajectSoort` and `afstandTotArbeidsmarkt` within 2 weeks

### REQ-PW-004: Mandatory AVG classification with financiële category

Every Participatiewet case contains sensitive financial data; classification MUST explicitly declare this.

**GIVEN** a klantmanager saves a bijstandsaanvraag without avgClassificatie  
**WHEN** the save is triggered  
**THEN** the system MUST reject and require the classification block.

**GIVEN** the zaak has been saved with `avgClassificatie.categorieen = ["financieel"]`  
**WHEN** the zaak is queried for access control  
**THEN** the system MUST apply the tightest access restrictions: only the assigned klantmanager + wijkteam peers can see content, and all reads are logged.

### REQ-PW-005: Access control limited to work-and-income team

Only the assigned klantmanager and their team (werk-en-inkomentteam) MAY read zaak content; other staff are blocked.

**GIVEN** a bijstandsaanvraag is assigned to klantmanager-477 (werk-en-inkomentteam)  
**WHEN** a staff member from a different wijkteam queries the zaak  
**THEN** the system MUST return only metadata (zaak number, status, dates) and block all financial/personal details.

**GIVEN** a functionaris gegevensbescherming needs to audit the case  
**WHEN** they access in FG-audit mode  
**THEN** they MUST receive metadata + auditLog without reading full financial data, flagged as "FG-audit".

### REQ-PW-006: Statutory retention (10 years post-closure) with deadline-driven destruction proposals

All Participatiewet cases MUST be retained for 10 years after closure, then marked for destruction.

**GIVEN** a ParticipatiewetZaak is closed on 2026-03-15  
**WHEN** vernietigingsDatum is calculated  
**THEN** it MUST be set to 2036-03-15 (exactly 10 years later).

**GIVEN** the current date is within 30 days of a zaak's vernietigingsDatum  
**WHEN** a batch job runs  
**THEN** the system MUST generate a vernietigingsvoorstel for archivaris approval.

### REQ-PW-007: Re-integratie-trajectory milestones and evaluatie scheduling

Re-integratie is an active, ongoing process with regular milestones and evaluaties.

**GIVEN** a ReIntegratieTraject is active  
**WHEN** quarterly evaluatie dates approach (within 14 days)  
**THEN** the system MUST create a task for the klantmanager to conduct a milestone review (job-search progress, training completion, etc.).

**GIVEN** a ReIntegratieTraject has `instrumenten` that include a loonkostensubsidie with a 12-month duration  
**WHEN** the end date approaches  
**THEN** the system MUST notify both the klantmanager and the employer that the subsidy period is ending and decide on next steps (extension, transition to unsubsidized employment, or loop back to job-search).

### REQ-PW-008: Automatic anonymization of financial data on export (unless explicit consent)

Participatiewet cases contain detailed financial information; exports MUST be anonymized unless the client has given explicit permission.

**GIVEN** financial data from a bijstandsaanvraag is being exported (e.g., for a statistical report or shared with an external agency without client consent)  
**WHEN** the export is triggered  
**WHEN** no toestemming record is found  
**THEN** the system MUST invoke `pii-detection-masking` to replace:
- BSN with pseudonym
- Exact income amounts with income-band ranges (€0–500, €501–1000, etc.)
- Vermogen with asset-band ranges
- Employer/creditor names with generic placeholders

**GIVEN** explicit toestemming exists (e.g., for tax authority data-sharing in re-integration case)  
**WHEN** the export proceeds  
**THEN** the system MUST send identified data + log the consent basis.

### REQ-PW-009: Comprehensive audit logging for financial-data access

Every read of sensitive financial data MUST be logged.

**GIVEN** a klantmanager opens a bijstandsaanvraag and views the vermoge + inkomens-gegevens  
**WHEN** the zaak is displayed  
**THEN** the system MUST log: `zaakId`, `medewerkerId`, `tijdstip`, `ipAdres`, `geraadpleegdeVelden` (specifically: vermogensToets, inkomensToets, etc.).

**GIVEN** a third-party agency (UWV, gemeente-accountant, tax authority) is granted read-access for a specific purpose  
**WHEN** they access the data  
**THEN** the system MUST log: `zaakId`, `requestingOrganisatie`, `doelGroep` (audit, compliance, etc.), `geautoriseerdeGegevens`, `tijdstip`.

### REQ-PW-010: Support for counter-services obligation (tegenprestatie) and exemptions

Participatiewet bijstandontvangers are obligated to participate in re-integratie (tegenprestatie) unless exempt.

**GIVEN** a ReIntegratieTraject is created  
**WHEN** the klantmanager reviews the applicant's circumstances  
**THEN** they MUST explicitly decide: is `tegenprestatieVerplicht=true` or is there an exemption reason (medisch, kinderopvang, ouderschap, etc.)?

**GIVEN** `tegenprestatieVerplicht=false` with reason "medische arbeidsongeschiktheid" (medical incapacity)  
**WHEN** the zaak is saved  
**THEN** the system MUST create a recurring task reminder every 6 months to reassess whether the medical exemption still applies.

## Seed data

Three realistic Participatiewet cases (Dutch context, typical economic hardship scenarios):

### Case 1: Young single parent, basic bijstand + wage subsidy re-integration
- **Case ID:** zaak-2026-pw-01278
- **Applicant:** BSN 234567890 (single mother, age 27)
- **Household:** Alleenstaand-met-kinderen (1 child, age 4)
- **Application date:** 2026-03-01
- **Requested start date:** 2026-03-15
- **Vermogenstoets:** 
  - Recorded: €2,400 assets
  - Threshold: €6,505 (single parent)
  - Result: Under threshold → OK
- **Inkomenstoets:**
  - Current income: €0 (unemployment, no other income)
  - Bijstandsnorm: €1,234.45/maand
  - Result: Well below norm → `rechtOpBijstand=true`
- **Re-integratie trajectory:**
  - Type: werkfit-maken
  - Afstand tot arbeidsmarkt: groot (long-term unemployment, skill-building needed)
  - Instrumenten:
    - Wage subsidy (60% loonwaarde, 12 months with Werkstap B.V.)
    - Training (Heftruckchauffeur, SVH-1 course, €1,850 budget)
    - Job coaching (3 months intensive)
  - Partners: UWV (no-risk-polis), Werkbedrijf Regio Zuid (matching)
- **Status:** re-integratie-loopt (after 2026-04-01 start)
- **Wijkteam:** werk-en-inkomentteam-oost
- **AVG classification:** Financieel (bijstandsgegevens) + gezinssituatie (single parent care burden), bewaarTermijn 10 jaren

### Case 2: Older worker transitioning from sickness benefit
- **Case ID:** zaak-2026-pw-02641
- **Applicant:** BSN 456789012 (male, age 58)
- **Household:** Alleenstaand (separated, no dependents)
- **Application date:** 2026-02-15
- **Reason:** Transition from long-term sickness benefit (wajong/WIA) to bijstand while re-integration attempts continue
- **Vermogenstoets:** €1,200 (under €6,505 threshold)
- **Inkomenstoets:** €350/month (partial disability benefit) < €1,234.45 norm → `rechtOpBijstand=true` (top-up bijstand)
- **Re-integratie trajectory:**
  - Type: scholing-specific (retraining for roles with health accommodations)
  - Afstand tot arbeidsmarkt: zeer-groot (age 58, health barriers, skill obsolescence)
  - Instrumenten:
    - Retraining (office administration, part-time 20 hr/wk)
    - Job coaching with health accommodations
    - Employer subsidy for hiring worker with health barriers
  - Partners: UWV, Training provider
- **Tegenprestatie:** Verplicht (job-search minimum 10 hr/week + training participation)
- **Evaluaties:** Quarterly (each 3 months)
- **Status:** re-integratie-loopt
- **AVG classification:** Financieel + medisch (health data informing accommodations), bewaarTermijn 10 jaren

### Case 3: Recent immigrant, inburgering-linked bijstand
- **Case ID:** zaak-2026-pw-03502
- **Applicant:** BSN 567890123 (female, age 31, recent immigrant)
- **Household:** Alleenstaand
- **Application date:** 2026-04-01
- **Reason:** Recent settlement; language/credential barriers to immediate employment
- **Aanvraagsoort:** inburgering-gerelateerde-bijstand (temporary income support while integrating + completing language + credential recognition)
- **Vermogenstoets:** €500
- **Inkomenstoets:** €0 → `rechtOpBijstand=true`
- **Re-integratie trajectory:**
  - Type: werkfit-maken
  - Afstand tot arbeidsmarkt: groot (language barriers, credential recognition pending)
  - Instrumenten:
    - Dutch language course (intensive, 3 months)
    - Credential recognition program (medical qualifications from origin country)
    - Internship placement (6 months) in healthcare sector
  - Partners: Training provider, Healthcare employer, Inburgering programme coordinator
- **Tegenprestatie:** Verplicht (language course + job-search + internship participation)
- **Expected trajectory duration:** 12–18 months
- **Status:** toetsing-afgerond → beschikking-voorbereiding (awaiting language-course confirmation)
- **AVG classification:** Financieel + gezinssituatie (integration support), bewaarTermijn 10 jaren

## Integration points

- **docudesk:** Beschikking-template for bijstand (legal decision letter, including vermoge + inkomens test results + appeal information)
- **openconnector:** UWV data-exchange (sickness benefit transition), employer wage-subsidy reporting, training-provider outcome tracking
- **mydash:** Work-and-income team dashboard (caseload, re-integratie milestones, overschredenTermijnen)
- **openregister:** Retention scheduling, RBAC (werk-en-inkomentteam only), audit-trail for financial-data access

## Design notes

- **Income-focused, not family-focused:** Unlike WMO (individual medical need) or Jeugdwet (family system), Participatiewet is fundamentally about **employment and income**. The zaak lifecycle is driven by income tests, benefit amounts, re-integratie milestones, not medical or family assessments.
- **Shorter retention (10 years vs. 15/20):** Reflects that bijstand is typically short-term support (avg 1–2 years); post-closure records are kept 10 years for dispute/reclaim scenarios but not indefinitely.
- **Active labor-market intervention:** The ReIntegratieTraject is not optional; it is a legal obligation (`tegenprestatie`) backed by benefit sanctions if not complied.
- **Lifecycle:** All transitions (toetsing → beschikking → re-integratie → afgesloten) declared as `x-openregister-lifecycle` per ADR-031.
- **Sensitive financial data:** Financial details are treated as special-category data despite not being AVG article 9 categories; access controls and anonymization are equally stringent.

