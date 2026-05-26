# Spec: WMO (Maatschappelijke Ondersteuning) zaaktype family

**Tier:** sociaal-domein  
**Statutory basis:** Wet maatschappelijke ondersteuning 2015 (Wmo 2015), artikel 2.3.2–2.3.6  
**Zaaktype identifier:** `wmo-melding`  
**Processing deadline:** 6 weeks (onderzoek) + 2 weeks (beschikking) = 8 weeks total

## Scope

WMO (maatschappelijke ondersteuning) is the statutory framework for municipal support of adults in vulnerable situations: elderly, disabled, chronically ill, or temporarily unable to manage household tasks. A WMO zaak begins with a melding (application or self-referral), progresses through assessment (onderzoek), and results in either:

- An *indicatiestelling* (recommended support type, volume, duration) → *beschikking* (formal decision to provide support)
- A decline decision

This spec covers the zaaktype definition, mandatory entities (Indicatiestelling), status flow, and integration with the broader sociaal-domein access/retention framework.

## Entities

### WmoZaak

Main case entity. Inherits from procest `case` with additional WMO-specific fields.

| Field | Type | Required | Description |
|---|---|---|---|
| zaaktype | reference | Y | Fixed: `wmo-melding` |
| bsn | string | Y | Dutch citizen ID (pseudonymized in audit if needed) |
| naam | string | Y | Client full name |
| aanvraagSoort | enum | Y | Type of support requested: `huishoudelijke-hulp`, `dagbesteding`, `begeleiding`, `hulpmiddelen`, `respijtzorg`, `other` |
| aanvraagDatum | date | Y | Date application/referral received |
| meldingKanaal | enum | Y | How was the melding received: `telefonisch`, `schriftelijk`, `persoonlijk-bezoek`, `verwijzing-huisarts`, `verwijzing-maatschappelijk-werker` |
| ondersteuningsvraag | string | Y | Client's stated need (free text, possibly verbatim from phone intake) |
| wijkteam | reference | Y | Assigned wijkteam (data-driven access guard will filter by this) |
| behandelaarId | string | Y | Primary caseworker (WMO-consulent) |
| tweedeBehandelaarId | string | N | Second handler (if team-wide coverage needed) |
| status | enum | Y | Lifecycle: `melding` → `onderzoek-loopt` → `beschikking-voorbereiding` → `beschikking-verleend` → `uitvoering` → `evaluatie` → `afgesloten` |
| avgClassificatie | object (AvgClassificatie) | Y | Mandatory classification block (see cross-cutting spec) |
| doorlooptijdWettelijk | object | Y | Tracking object: `onderzoekTermijnWeken` (6), `beschikkingTermijnWeken` (2), `totaalWettelijkWeken` (8), plus `onderzoekFeitelijkGereedDatum`, `beschikkingFeitelijkGereedDatum` for deadline monitoring |
| indicatiestellingId | reference | N | Link to associated Indicatiestelling (optional until indicatie is created) |
| huishoudensSamenstelling | object | N | Demographic: `type` (alleenstaand, paar, alleenstaand-met-kinderen, etc.), `leeftijdsgroep` (0-4, 5-17, 18-64, 65-74, 75-plus), `mantelzorgAanwezig` (boolean) |

### Indicatiestelling

Assessment record documenting the WMO-consulent's professional judgment of what support is appropriate.

| Field | Type | Required | Description |
|---|---|---|---|
| zaakId | reference | Y | Parent WmoZaak |
| indicatieSteller | string | Y | WMO-consulent who performed assessment |
| datumOnderzoek | date | Y | Date of investigation (may be different from aanvraagDatum if application predates handoff) |
| vorm | enum | Y | Assessment type: `huisbezoek`, `telefonisch`, `dossieronderzoek` |
| onderzoekVerslag | reference | N | Nextcloud file ID of investigation report (uploaded document) |
| geadviseerdeOndersteuning | object | Y | Recommended support: `soort` (support type, matches WmoZaak.aanvraagSoort), `omvangPerWeek` (hours), `eenheid` (uur, dagdelen, etc.), `duurMaanden` (duration), `leverancierKeuzeBurger` (boolean: can client choose provider?) |
| beschikkingId | reference | N | Link to resulting Beschikking (set after beschikking is issued) |
| evaluatieDatum | date | N | Scheduled date for re-evaluation (typically 12 months post-implementation) |

## Requirements

### REQ-WMO-001: WMO zaaktype definition and status lifecycle

The system MUST support a `wmo-melding` zaaktype with mandatory status transitions and wettelijke termijn tracking.

**GIVEN** a WMO-consulent creates a new WMO case  
**WHEN** zaaktype is set to `wmo-melding`  
**THEN** the case MUST automatically:
- Initialize status to `melding`
- Populate `doorlooptijdWettelijk` with (6, 2, 8) weeks
- Require `avgClassificatie` before save is allowed
- Generate a case identifier in format `zaak-{year}-wmo-{5-digit-sequence}`

### REQ-WMO-002: Indicatiestelling creation and assessment flow

The system MUST support the two-phase assessment flow: initial underzoek → indicatiestelling → beschikking.

**GIVEN** a WmoZaak has status `melding`  
**WHEN** the consulent uploads `onderzoekVerslag` and creates an Indicatiestelling  
**THEN** the system MUST:
- Validate that indicatiestelling.datumOnderzoek is within 6 weeks of aanvraagDatum
- Auto-transition WmoZaak status to `beschikking-voorbereiding`
- Record the beschikkingTermijn deadline (2 weeks from indicatiestelling date)
- Notify behandelaar that beschikking-drafting must begin

### REQ-WMO-003: Mandatory AVG classification at zaak creation

Every WMO case concerns vulnerable adults; the zaak MUST declare its special-category data scope at creation.

**GIVEN** a WMO-consulent saves a new zaak without an `avgClassificatie` block  
**WHEN** the save is triggered  
**THEN** the system MUST reject the save with a validation error and require the classification block to be filled.

**GIVEN** the indicatiestelling advises "medisch hulpmiddel" (e.g., mobility aid for post-surgery recovery)  
**WHEN** the zaak is saved  
**THEN** the `avgClassificatie.categorieen` MUST include at least `medisch` and `bijzonderePersoonsgegevens` MUST be set to `true`.

### REQ-WMO-004: Wijkteam-only access control

Only staff in the assigned wijkteam MAY read the zaak's content; other staff MAY only read metadata.

**GIVEN** WmoZaak `zaak-2026-wmo-04832` has `wijkteam = wijkteam-zuid`  
**WHEN** a staff member from `wijkteam-noord` queries the zaak  
**THEN** the system MUST return only: zaak number, status, treatment dates, but NOT ondersteuningsvraag, indicatiestelling, or any inhoud fields.

**GIVEN** a staff member is marked as `tweedeBehandelaarId`  
**WHEN** they query the zaak  
**THEN** they MUST receive full access, regardless of wijkteam membership.

### REQ-WMO-005: Automatic beschikking generation from indicatiestelling

The beschikking letter MUST be auto-generated from the indicatiestelling data without manual transcription.

**GIVEN** an indicatiestelling records "huishoudelijke-hulp, 4 uur per week, 12 maanden"  
**WHEN** the beschikking is generated (via docudesk template)  
**THEN** the beschikking text MUST automatically populate these exact values without separate data entry.

### REQ-WMO-006: Wettelijke termijn monitoring and overschrijding tracking

The system MUST track actual vs. statutory deadlines and flag overschrijdingen.

**GIVEN** a WmoZaak is in status `onderzoek-loopt` and onderzoekTermijn (6 weeks) has elapsed  
**WHEN** a daily batch job runs  
**THEN** the system MUST:
- Set a flag `onderzoekTermijnOverschredenSinds` to the exceeded date
- Notify wijkteam-manager that a deadline has been missed
- Optionally generate a termijnverlening (extension request) task

### REQ-WMO-007: Re-evaluation scheduling and lifetime-of-support tracking

WMO support may be ongoing or time-limited; the system MUST manage re-evaluation cycles.

**GIVEN** an Indicatiestelling records `duurMaanden = 12` and `evaluatieDatum = 2027-03-28`  
**WHEN** the evaluatie date approaches (within 30 days)  
**THEN** the system MUST create a task for the consulent to schedule a re-evaluation contact.

**GIVEN** the zaak's support is ongoing (no fixed end date)  
**WHEN** the evaluatie is completed  
**THEN** the consulent MUST be able to record a new Indicatiestelling to extend the support (creating a new support cycle rather than a timeline jump).

### REQ-WMO-008: Automatic anonymization on export to external providers

If a WMO zaak is exported to a zorgaanbieder or other external party without recorded toestemming, PII MUST be automatically anonymized.

**GIVEN** a zaak export is triggered (e.g., via openconnector to a zorgaanbieder)  
**WHEN** the system checks for recorded toestemming and finds none  
**THEN** the system MUST invoke `pii-detection-masking` to replace:
- BSN with pseudonym (e.g., "zaak-id-client-0001")
- Geboortedatum with age-range only
- Medische details with functional-impact summary (no clinical diagnosis)

**GIVEN** a toestemming record exists for the export target  
**WHEN** the export is triggered  
**THEN** the system MUST send the identified data without anonymization.

### REQ-WMO-009: Statutory retention (15 years) and destruction proposal

All WMO cases MUST be retained for 15 years post-closure and then marked for destruction.

**GIVEN** a WmoZaak is closed on 2026-03-15  
**WHEN** the zaak's `vernietigingDatum` is calculated  
**THEN** it MUST be set to 2041-03-15 (exactly 15 years later).

**GIVEN** the current date is within 30 days of a zaak's vernietigingDatum  
**WHEN** a daily batch job runs  
**THEN** the system MUST generate a `vernietigingsvoorstel` (archivaris task) to review and approve destruction.

### REQ-WMO-010: Comprehensive audit logging of data access

Every read-action on a WMO zaak with medische gegevens MUST be logged.

**GIVEN** a WMO-consulent opens a zaak with `avgClassificatie.categorieen = ["medisch"]`  
**WHEN** the zaak is displayed  
**THEN** the system MUST create an audit-log entry with:
- `zaakId`, `medewerkerId`, `tijdstip`, `ipAdres`
- `geraadpleegdeVelden`: which specific fields were accessed (ondersteuningsvraag, indicatiestelling, etc.)

**GIVEN** a functionaris gegevensbescherming generates a "who has seen this citizen's data" report  
**WHEN** the report is generated  
**THEN** the system MUST list all audit-log entries for all WMO zaakken of that citizen, sorted by date.

## Seed data

Three realistic WMO cases (Dutch context, typical referral patterns):

### Case 1: Post-surgical temporary support
- **Case ID:** zaak-2026-wmo-04832
- **Client:** Janssen-de Vries, M.A. (BSN: 123456789)
- **Age group:** 75-plus
- **Referral channel:** Telefonisch (self-referral after discharge from hospital)
- **Request:** Huishoudelijke hulp (temporary, 4 hr/wk post-heupoperatie)
- **Status:** beschikking-verleend
- **Indicatiestelling:** huishoudelijke-hulp, 4 uur/wk, 12 maanden (post-surgery recovery period)
- **Household:** Alleenstaand, no mantelzorg
- **AVG classification:** Medisch (post-surgical status), bewaarTermijn 15 jaar

### Case 2: Long-term dementia support
- **Case ID:** zaak-2026-wmo-07415
- **Client:** Bakker, P. (BSN: 987654321)
- **Age group:** 65-74
- **Referral channel:** Verwijzing huisarts (diagnose: mild dementia + ADL decline)
- **Request:** Dagbesteding + begeleiding
- **Status:** uitvoering
- **Indicatiestelling:** Dagbesteding (3 days/week), begeleiding thuis (2 visits/wk), ongoing (no fixed end date due to progressive condition)
- **Household:** Paar (with spouse as informal carer), family requests respijtzorg (respite care)
- **AVG classification:** Medisch + gezinssituatie, bewaarTermijn 15 jaar

### Case 3: Young parent after childbirth
- **Case ID:** zaak-2026-wmo-05921
- **Client:** Smit, J. (BSN: 654321098)
- **Age group:** 18-64
- **Referral channel:** Verwijzing maatschappelijk werker (post-partum support, single parent stress)
- **Request:** Hulpverlening + hulpmiddelen (ergonomic seating for breastfeeding)
- **Status:** beschikking-voorbereiding
- **Indicatiestelling:** (pending) Expected: huishoudelijke-hulp (6 wk postpartum), begeleiding moeder-kind (8 weeks), hulpmiddelen (nursing pillow, ergonomic chair)
- **Household:** Alleenstaand-met-kinderen (1 infant)
- **Wijkteam:** wijkteam-oost
- **AVG classification:** Medisch + gezinssituatie, bewaarTermijn 15 jaar

## Integration points

- **docudesk:** Beschikking-template for WMO (letter format per gemeente, auto-filled from Indicatiestelling data)
- **openconnector:** iWMO berichtenverkeer with zorgaanbieders (notify provider when beschikking issued, receive status updates)
- **mydash:** Wijkteam dashboard widget showing WMO zaak counts, doorlooptijden, overschredenTermijnen per caseworker
- **openregister:** Retention scheduling, RBAC guards (wijkteam override), audit-trail immutability

## Design notes

- **No parallel storage:** WmoZaak is fully OpenRegister-backed; no separate `WmoZaakMapper` or custom persistence layer.
- **Lifecycle:** Status transitions are declared as `x-openregister-lifecycle` in the register schema patch; no custom workflow engine.
- **Access model:** Wijkteam membership is checked at query time (data-driven, not role-driven), per ADR-022 & AVG-compliance principle.
- **Audit readiness:** Every read of medische data is logged to support subject-access-request (burgerrecht) and FG-audit scenarios.

