---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# procest-sociaal-domein-wmo Specification

## Purpose
TBD - created by archiving change sociaal-domein-zaaktypes. Update Purpose after archive.
## Requirements
### Requirement: WMO zaaktype definition and status lifecycle
The system MUST support a `wmo-melding` zaaktype backed entirely by OpenRegister with mandatory status transitions and wettelijke termijn tracking. The `WmoZaak` entity carries the fields `zaaktype` (fixed `wmo-melding`), `bsn`, `naam`, `aanvraagSoort` (`huishoudelijke-hulp`/`dagbesteding`/`begeleiding`/`hulpmiddelen`/`respijtzorg`/`other`), `aanvraagDatum`, `meldingKanaal`, `ondersteuningsvraag`, `wijkteam`, `behandelaarId`, `tweedeBehandelaarId` (optional), `status`, `avgClassificatie` (required), `doorlooptijdWettelijk`, `indicatiestellingId` (optional), and `huishoudensSamenstelling` (optional). The status lifecycle (`melding` → `onderzoek-loopt` → `beschikking-voorbereiding` → `beschikking-verleend` → `uitvoering` → `evaluatie` → `afgesloten`) MUST be declared as `x-openregister-lifecycle` in the register schema patch, not as a custom workflow engine.

#### Scenario: New WMO case is initialized with statutory deadlines
- **GIVEN** a WMO-consulent creates a new case
- **WHEN** zaaktype is set to `wmo-melding`
- **THEN** the case status is initialized to `melding`
- **AND** `doorlooptijdWettelijk` is populated with onderzoek 6 weeks, beschikking 2 weeks, totaal 8 weeks
- **AND** the case cannot be saved until `avgClassificatie` is filled
- **AND** a case identifier is generated in the format `zaak-{year}-wmo-{5-digit-sequence}`

### Requirement: Indicatiestelling creation and assessment flow
The system MUST support the two-phase assessment flow (onderzoek → indicatiestelling → beschikking) via an OpenRegister-backed `Indicatiestelling` entity carrying `zaakId`, `indicatieSteller`, `datumOnderzoek`, `vorm` (`huisbezoek`/`telefonisch`/`dossieronderzoek`), `onderzoekVerslag` (optional NC file reference), `geadviseerdeOndersteuning` (object with `soort`, `omvangPerWeek`, `eenheid`, `duurMaanden`, `leverancierKeuzeBurger`), `beschikkingId` (optional), and `evaluatieDatum` (optional).

#### Scenario: Indicatiestelling advances the case and sets the beschikking deadline
- **GIVEN** a `WmoZaak` has status `melding`
- **WHEN** the consulent uploads `onderzoekVerslag` and creates an `Indicatiestelling`
- **THEN** the system validates that `datumOnderzoek` is within 6 weeks of `aanvraagDatum`
- **AND** the `WmoZaak` status auto-transitions to `beschikking-voorbereiding`
- **AND** the beschikking deadline is recorded as 2 weeks from the indicatiestelling date
- **AND** the behandelaar is notified that beschikking-drafting must begin

### Requirement: Mandatory AVG classification at zaak creation
Every WMO case concerns vulnerable adults; the zaak MUST declare its special-category data scope (`avgClassificatie`) before it can be saved, per the cross-cutting AVG spec and AVG art. 9.

#### Scenario: Save is rejected without a classification block
- **GIVEN** a WMO-consulent saves a new zaak without an `avgClassificatie` block
- **WHEN** the save is triggered
- **THEN** the system rejects the save with a validation error requiring the classification block

#### Scenario: Medical support forces bijzondere-persoonsgegevens flag
- **GIVEN** the indicatiestelling advises a medical aid (e.g., a mobility aid for post-surgery recovery)
- **WHEN** the zaak is saved
- **THEN** `avgClassificatie.categorieen` includes at least `medisch`
- **AND** `avgClassificatie.bijzonderePersoonsgegevens` is `true`

### Requirement: Wijkteam-only access control
Only staff in the assigned wijkteam MAY read a zaak's content; other staff MAY read only metadata. Access MUST be enforced at query time by comparing `zaak.wijkteam` to the requesting user's wijkteam (data-driven, not role-driven alone), with a `tweedeBehandelaarId` override.

#### Scenario: Out-of-team staff see metadata only
- **GIVEN** `WmoZaak` `zaak-2026-wmo-04832` has `wijkteam = wijkteam-zuid`
- **WHEN** a staff member from `wijkteam-noord` queries the zaak
- **THEN** the response contains only zaak number, status, and treatment dates
- **AND** it does NOT contain `ondersteuningsvraag`, the indicatiestelling, or any inhoud fields

#### Scenario: Second handler gets full access regardless of team
- **GIVEN** a staff member is recorded as `tweedeBehandelaarId`
- **WHEN** they query the zaak
- **THEN** they receive full access regardless of wijkteam membership

### Requirement: Automatic beschikking generation from indicatiestelling
The beschikking letter MUST be auto-generated from indicatiestelling data (via the docudesk template) without manual transcription.

#### Scenario: Beschikking text is populated from indicatiestelling fields
- **GIVEN** an indicatiestelling records `huishoudelijke-hulp`, 4 uur per week, 12 maanden
- **WHEN** the beschikking is generated via the docudesk template
- **THEN** the beschikking text automatically contains these exact values without separate data entry

### Requirement: Wettelijke termijn monitoring and overschrijding tracking
The system MUST track actual versus statutory deadlines and flag overschrijdingen via a scheduled job.

#### Scenario: Exceeded onderzoek deadline is flagged and escalated
- **GIVEN** a `WmoZaak` is in status `onderzoek-loopt` and the 6-week onderzoek deadline has elapsed
- **WHEN** the daily batch job runs
- **THEN** the flag `onderzoekTermijnOverschredenSinds` is set to the exceeded date
- **AND** the wijkteam-manager is notified of the missed deadline
- **AND** a termijnverlening (extension request) task may be generated

### Requirement: Re-evaluation scheduling and lifetime-of-support tracking
WMO support may be ongoing or time-limited; the system MUST manage re-evaluation cycles.

#### Scenario: Re-evaluation task is created as the evaluatie date approaches
- **GIVEN** an `Indicatiestelling` records `duurMaanden = 12` and `evaluatieDatum = 2027-03-28`
- **WHEN** the evaluatie date is within 30 days
- **THEN** a task is created for the consulent to schedule a re-evaluation contact

#### Scenario: Ongoing support is extended via a new indicatiestelling
- **GIVEN** the zaak's support is ongoing with no fixed end date
- **WHEN** the evaluatie is completed
- **THEN** the consulent can record a new `Indicatiestelling` to extend the support as a new support cycle

### Requirement: Automatic anonymization on export to external providers
If a WMO zaak is exported to a zorgaanbieder or other external party without recorded toestemming, PII MUST be automatically anonymized via `pii-detection-masking`.

#### Scenario: Export without consent is anonymized
- **GIVEN** a zaak export is triggered (e.g., via openconnector to a zorgaanbieder)
- **WHEN** the system finds no recorded toestemming
- **THEN** `pii-detection-masking` replaces BSN with a pseudonym, geboortedatum with an age-range, and medical detail with a functional-impact summary

#### Scenario: Export with consent sends identified data
- **GIVEN** a toestemming record exists for the export target
- **WHEN** the export is triggered
- **THEN** the system sends the identified data without anonymization

### Requirement: Statutory retention (15 years) and destruction proposal
All WMO cases MUST be retained for 15 years post-closure (selectielijst) and then proposed for destruction under archivaris review.

#### Scenario: Vernietigingsdatum is 15 years after closure
- **GIVEN** a `WmoZaak` is closed on 2026-03-15
- **WHEN** the `vernietigingDatum` is calculated
- **THEN** it is set to 2041-03-15

#### Scenario: Destruction proposal is generated 30 days before the deadline
- **GIVEN** the current date is within 30 days of a zaak's `vernietigingDatum`
- **WHEN** the daily batch job runs
- **THEN** a `vernietigingsvoorstel` archivaris task is generated to review and approve destruction

### Requirement: Comprehensive audit logging of data access
Every read-action on a WMO zaak with medische gegevens MUST be logged immutably to support subject-access-requests and FG-audits.

#### Scenario: Reading medical data writes an audit entry
- **GIVEN** a WMO-consulent opens a zaak with `avgClassificatie.categorieen = ["medisch"]`
- **WHEN** the zaak is displayed
- **THEN** an audit-log entry is created with `zaakId`, `medewerkerId`, `tijdstip`, `ipAdres`, and `geraadpleegdeVelden`

#### Scenario: FG report lists all access for a citizen
- **GIVEN** a functionaris gegevensbescherming generates a "who has seen this citizen's data" report
- **WHEN** the report is generated
- **THEN** all audit-log entries for all WMO zaakken of that citizen are listed, sorted by date

### Requirement: WMO seed data for exploration
The implementation chain MUST load three realistic WMO seed cases (OpenRegister-backed) so testers can immediately explore the zaaktype.

#### Scenario: Three representative WMO cases are seeded
- **GIVEN** the WMO register patch is applied with seed data
- **WHEN** a tester lists WMO cases
- **THEN** a post-surgical temporary-support case (`zaak-2026-wmo-04832`, 75-plus, huishoudelijke-hulp 4u/wk/12mnd, status `beschikking-verleend`, AVG medisch, 15-jaar bewaartermijn) is present
- **AND** a long-term dementia case (`zaak-2026-wmo-07415`, 65-74, dagbesteding + begeleiding, status `uitvoering`, ongoing, AVG medisch + gezinssituatie) is present
- **AND** a young-parent post-childbirth case (`zaak-2026-wmo-05921`, 18-64, hulpverlening + hulpmiddelen, status `beschikking-voorbereiding`, wijkteam-oost, AVG medisch + gezinssituatie) is present

