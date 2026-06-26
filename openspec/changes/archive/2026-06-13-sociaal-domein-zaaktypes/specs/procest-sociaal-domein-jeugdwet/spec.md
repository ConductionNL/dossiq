# Spec delta: procest-sociaal-domein-jeugdwet

**Tier:** sociaal-domein
**Statutory basis:** Jeugdwet 2015, artikel 2.3 (jeugdhulpplicht), 6.1.2 (gezinsplan), 7.3 (data processing)
**Zaaktype identifier:** `jeugdwet-melding`
**Processing deadline:** 4 weeks (gezinsplan) + 2 weeks (decision) = 6 weeks total

Jeugdwet (Wet op de jeugdhulp) is the statutory framework for municipal support of children (0–17) and their families in crisis or requiring intervention: referral (melding) → multi-professional assessment and family-centered planning (gezinsplan) → coordination across services via Multi-Disciplinary Overleg (MDO) → ongoing support with scheduled re-evaluations and possible extensions. Unlike WMO (adult, individual) or Participatiewet (income/work), Jeugdwet is **family-centered** and **multi-agency**, requiring explicit family consent and coordination. This delta defines the zaaktype, the `Gezinsplan`, `MdoOverleg`, and reused `Toestemming` entities, the status flow, and integration with the cross-cutting AVG/consent framework.

## ADDED Requirements

### Requirement: Jeugdwet zaaktype definition and family-centered status lifecycle
The system MUST support a `jeugdwet-melding` zaaktype backed entirely by OpenRegister with a family-centered status flow declared as `x-openregister-lifecycle` (`melding` → `gezinsplan-opstellen` → `gezinsplan-gereed` → `ondersteuning-gestart` → `ondersteuning-loopt` → `evaluatie` → `afgesloten`, with a `verlenging-aangevraagd` branch). The `JeugdwetZaak` entity carries `zaaktype` (fixed `jeugdwet-melding`), `gezinId`, `jeugdigeBsn`, `jeugdigeLeeftijd`, `verzoekKanaal`, `verzoekDatum`, `verwijzer`, `ondersteuningsvraag`, `wijkteam`, `behandelaarId`, `status`, `avgClassificatie` (required), `gezinsplanId` (optional), `mdoOverlegIds` (optional array), `ondertoezichtstellingActief` (optional), and `verlengingHistorie` (optional array).

#### Scenario: New Jeugdwet case is initialized and pre-creates a gezinsplan
- **GIVEN** a jeugdconsulent creates a new Jeugdwet case
- **WHEN** zaaktype is set to `jeugdwet-melding`
- **THEN** the status is initialized to `melding`
- **AND** `avgClassificatie` (typically including `gezinssituatie`) is required before save
- **AND** a case identifier is generated as `zaak-{year}-jeugd-{5-digit-sequence}`
- **AND** an empty `Gezinsplan` object is pre-created for the consulent to complete

### Requirement: Gezinsplan creation and family consent workflow
The system MUST support gezinsplan drafting and explicit family agreement via an OpenRegister-backed `Gezinsplan` entity carrying `zaakId`, `opgesteldDoor`, `opgesteldDatum`, `gezinsleden` (array of `rol`, `bsn`, `akkoord`, `akkoordDatum`, `leeftijdToestemmingsvereiste`), `doelen`, `inzetTrajecten`, `evaluatieMomenten` (optional), `verlengingMogelijk`, and `verlengingVan` (optional). Plan approval MUST be blocked until all required consents are recorded; from age 16 the jeugdige is an independent consenting party.

#### Scenario: Drafting a plan creates consent tasks and blocks approval
- **GIVEN** a jeugdconsulent completes a family assessment and drafts a gezinsplan
- **WHEN** the plan is saved
- **THEN** the `JeugdwetZaak` status is set to `gezinsplan-gereed`
- **AND** consent-recording tasks are created for all gezinsleden (and the jeugdige if 16+)
- **AND** plan approval is blocked until all required signatures are recorded

#### Scenario: A 16+ jeugdige must consent independently
- **GIVEN** a gezinslid is age 16 or older
- **WHEN** a gezinsplan is drafted
- **THEN** that individual's `akkoord` must be explicitly recorded
- **AND** consent cannot be assumed via a guardian signature alone

### Requirement: Mandatory AVG classification with special attention to family/behavioral data
Every Jeugdwet case concerns minors; the `avgClassificatie` MUST be present before save and explicitly flag family and behavioral data, with a retention of at least 20 years.

#### Scenario: Save is rejected without classification
- **GIVEN** a jeugdconsulent creates a `JeugdwetZaak`
- **WHEN** the zaak is saved without `avgClassificatie`
- **THEN** the system rejects the save and requires classification

#### Scenario: Behavioral/family cases force gezinssituatie category and 20-year retention
- **GIVEN** the case involves behavioral concerns or family conflict
- **WHEN** classification is completed
- **THEN** `categorieen` includes `gezinssituatie`
- **AND** the `bewaarTermijnJaren` is set to 20 (Jeugdwet retention standard) or longer

### Requirement: Multi-Disciplinary Overleg (MDO) with explicit external-party consent
Jeugdwet zaakken often require cross-agency coordination through an OpenRegister-backed `MdoOverleg` entity carrying `zaakIds`, `overlegDatum`, `deelnemers` (with `toestemmingDeelnameDoorClient`), `agenda` (optional), `verslag` (optional), `toestemmingenGeregistreerd`, and `gedeeldeGegevens` (`alle-gegevens` / `alleen-anonimiseerde-samenvatting`). External (non-gemeente) participants MUST have an explicit `Toestemming` record permitting data sharing.

#### Scenario: MDO with external party checks for consent
- **GIVEN** a jeugdconsulent schedules an MDO with a schoolmaatschappelijk werker from an externe organisatie
- **WHEN** the MDO is created
- **THEN** the system checks for a `Toestemming` record explicitly permitting data-sharing with that school

#### Scenario: Missing consent warns, logs, and anonymizes
- **GIVEN** no toestemming is found
- **WHEN** the MDO meeting proceeds
- **THEN** a warning is shown to the consulent
- **AND** the meeting is logged as having proceeded without recorded consent
- **AND** any data shared in the MDO verslag is automatically anonymized (names → roles, details → functional summaries)

#### Scenario: Present consent records the legal basis
- **GIVEN** toestemming is found and in effect
- **WHEN** the MDO verslag is finalized
- **THEN** the system logs which specific data (from `tegegevens`) was shared and on what legal basis

### Requirement: Gezinsplan evaluation and extension workflow
The system MUST support gezinsplan evaluation and extension when initial goals are not met within the trajectory.

#### Scenario: Evaluation task is created as an evaluatieMoment approaches
- **GIVEN** a gezinsplan is due for evaluation at a scheduled `evaluatieMoment`
- **WHEN** the date is within 14 days
- **THEN** a task is created for the consulent to conduct the evaluatie

#### Scenario: Extension creates a linked new plan and resets consent
- **GIVEN** evaluation shows insufficient progress and `verlengingMogelijk = true`
- **WHEN** the consulent decides to extend support
- **THEN** a new `Gezinsplan` is created with the same `zaakId` (updating `gezinsplanId`)
- **AND** the new plan links to the old one via `verlengingVan`
- **AND** the old plan ID is appended to `JeugdwetZaak.verlengingHistorie`
- **AND** all gezinsleden consent is reset to `akkoord = false` and a re-consent round is started

### Requirement: Access control with special FG/child-safety overrides
Jeugdwet cases can involve child safety; access MUST be jeugdteam-scoped but MUST allow a logged child-protection override.

#### Scenario: Only the jeugdteam sees content
- **GIVEN** a jeugdconsulent opens a `JeugdwetZaak`
- **WHEN** the case is queried
- **THEN** only the jeugdteam and assigned consulent may see case content

#### Scenario: Child-safety escalation allows logged unanonymized sharing
- **GIVEN** a child-safeguarding concern triggers escalation to child-protection authorities
- **WHEN** data-sharing with Veilig Thuis is needed
- **THEN** the override reason is logged (e.g., "verdacht kindermishandeling per art. 47c Jeugdwet")
- **AND** unanonymized sharing proceeds because child safety takes precedence
- **AND** an audit-log entry records the exceptional disclosure

### Requirement: Automatic anonymization of MDO minutes when consent is not recorded
If an MDO involves external parties without recorded family consent, the verslag MUST be anonymized via `pii-detection-masking`.

#### Scenario: Unconsented MDO minutes are redacted
- **GIVEN** an MDO involves a GGD jeugdarts and no toestemming is found
- **WHEN** the MDO verslag is drafted
- **THEN** the child's name is replaced with "jeugdige, leeftijd X"
- **AND** family names are replaced with roles ("ouders", "familielid A/B")
- **AND** specific diagnoses are replaced with functional summaries ("gedragsproblemen", "schoolmoeilijkheden")
- **AND** named zorgaanbieders are replaced with "huidige provider"/"proposed provider"

#### Scenario: Identifying data triggers consistent masking
- **GIVEN** the jeugdige's BSN or other identifying data exists in the minutes draft
- **WHEN** anonimisering is applied
- **THEN** `pii-detection-masking` from openregister is invoked to ensure consistent redaction

### Requirement: Statutory retention (20 years) and destruction proposal
All Jeugdwet cases MUST be retained for 20 years post-closure and then proposed for destruction.

#### Scenario: Vernietigingsdatum is 20 years after closure
- **GIVEN** a `JeugdwetZaak` is closed on 2026-03-15
- **WHEN** `vernietigingsDatum` is calculated
- **THEN** it is set to 2046-03-15

#### Scenario: Destruction proposal generated near the deadline
- **GIVEN** the current date is within 30 days of `vernietigingsDatum`
- **WHEN** the batch job runs
- **THEN** a `vernietigingsvoorstel` is generated for archivaris review

### Requirement: Comprehensive audit logging with focus on external-party data access
Every read of Jeugdwet case data MUST be logged, with particular attention to reads by externe organisaties.

#### Scenario: Internal read is logged
- **GIVEN** a jeugdconsulent from the gemeente opens a `JeugdwetZaak`
- **WHEN** the case is displayed
- **THEN** the system logs `zaakId`, `medewerkerId`, `organisatie`, `tijdstip`, and `geraadpleegdeVelden`

#### Scenario: External provider read under consent is logged
- **GIVEN** an externe jeugdzorg provider is granted read-access to the gezinsplan under toestemming
- **WHEN** they access the data
- **THEN** the system logs `zaakId`, `partnerOrganisatie`, `tijdstip`, and `geautoriseerdeGegevens` (from `toestemming.tegegevens`)

### Requirement: Subject-access-request (burgerrecht) support for jeugdige and parents
Jeugdwet cases may generate subject-access requests from both the child and parents; the system MUST support these.

#### Scenario: Parent SAR generates a comprehensive report
- **GIVEN** a parent files a subject-access request (AVG art. 15) for their child's jeugdwet zaakken
- **WHEN** the FG processes the request
- **THEN** a report lists all `JeugdwetZaakken` for that child, all `Gezinsplanen`, `MdoOverleg` records and `Toestemmingen`, the audit log of who accessed the data and when, and all attached documents

### Requirement: Jeugdwet seed data for exploration
The implementation chain MUST load three realistic Jeugdwet seed cases (OpenRegister-backed) so testers can explore the zaaktype.

#### Scenario: Three representative Jeugdwet cases are seeded
- **GIVEN** the Jeugdwet register patch is applied with seed data
- **WHEN** a tester lists Jeugdwet cases
- **THEN** a post-divorce behavioral case (`zaak-2026-jeugd-00921`, age 9, gezin-04472, ambulante jeugdhulp, status `ondersteuning-loopt`, jeugdteam-noord, AVG gezinssituatie + medisch, 20-jaar bewaartermijn) is present
- **AND** a school-refusal-with-depression case (`zaak-2026-jeugd-01847`, age 16, status `gezinsplan-gereed` awaiting the 16+ jeugdige's signature, jeugdteam-zuid, AVG medisch + gezinssituatie) is present
- **AND** a toddler early-intervention case (`zaak-2026-jeugd-02456`, age 2, single parent, status `ondersteuning-gestart`, jeugdteam-west, AVG medisch + gezinssituatie) is present

## Integration points

- **docudesk:** Gezinsplan template (family-friendly printable), beschikking template (formal decision letter)
- **openconnector:** iJW berichtenverkeer with jeugdzorg providers; CJG coordination; GGD referrals
- **launchpad:** Jeugdteam dashboard (caseload, evaluation-due dates, extension-pending counts)
- **openregister:** Toestemming-driven RBAC for external read-access, retention scheduling, audit-trail immutability

## Design notes

- **No parallel storage (ADR-022):** `JeugdwetZaak`, `Gezinsplan`, `MdoOverleg` and `Toestemming` are fully OpenRegister-backed; no custom mappers or persistence layer.
- **No custom state machine (ADR-031):** all status transitions and evaluatie scheduling are declared as `x-openregister-lifecycle`.
- **Manifest navigation (ADR-024):** `jeugdwet-melding` is discoverable from procest's case-type selector via the register manifest.
- **Family-centered framing:** the *family system* (gezinsleden, gezinsdoelen, family-wide evaluatie) is the unit of analysis.
- **Multi-agency coordination:** MDO support is foundational; external-party participation and consent are tracked explicitly.
- **Child agency:** from age 16 the child is a consenting party, not just a subject of the plan.
- **Longer retention:** 20-year retention (vs. 15 for WMO) reflects lifelong developmental impact.
