---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# procest-sociaal-domein-participatiewet Specification

## Purpose
TBD - created by archiving change sociaal-domein-zaaktypes. Update Purpose after archive.
## Requirements
### Requirement: Participatiewet zaaktype definition and income-focused status lifecycle
The system MUST support a `bijstandsaanvraag` zaaktype backed entirely by OpenRegister, requiring financial testing before benefit approval. The `ParticipatiewetZaak` entity carries `zaaktype` (fixed `bijstandsaanvraag`), `bsn`, `aanvraagSoort` (`algemene-bijstand`/`inburgering-gerelateerde-bijstand`/`bijstand-werkloze-uitkeringontvangers`/`noodvoorziening`), `aanvraagDatum`, `ingangsdatumGewenst`, `leeftijdsgroep`, `huishoudensSituatie`, `vermogensToets` (object), `inkomensToets` (object), `reIntegratieTrajectId` (optional), `behandelaarId`, `status`, and `avgClassificatie` (required). The lifecycle (`aanvraag-ontvangen` → `toetsing-loopt` → `toetsing-afgerond` → `beschikking-voorbereiding` → `beschikking-gereed` → `bijstand-actief` → `re-integratie-loopt` → `afgesloten`) MUST be declared as `x-openregister-lifecycle`.

#### Scenario: New Participatiewet case is initialized with test placeholders
- **GIVEN** a klantmanager creates a new Participatiewet case
- **WHEN** zaaktype is set to `bijstandsaanvraag`
- **THEN** the status is initialized to `aanvraag-ontvangen`
- **AND** placeholder `vermogensToets` and `inkomensToets` objects are created (both `uitgevoerd = false`)
- **AND** `avgClassificatie` (typically `financieel`) is required before save
- **AND** progression to beschikking is blocked until both toetsen are `uitgevoerd = true`
- **AND** a case identifier is generated as `zaak-{year}-pw-{5-digit-sequence}`

### Requirement: Mandatory vermogens- and inkomenstoets with automatic disposition
The two-test sequence MUST be completed before any benefit decision; vermogen above the threshold MUST auto-trigger a refusal recommendation.

#### Scenario: Excess assets auto-trigger a refusal recommendation
- **GIVEN** a klantmanager executes the vermogenstoets and records vermogen above `vermogensvrijstelling`
- **WHEN** the test is saved
- **THEN** `boven_vermogensvrijstelling` is set to `true`
- **AND** the zaak status auto-transitions to `toetsing-afgerond` with an adverse result
- **AND** the zaak is marked as an `afwijzingsvoorstel` with pre-filled motivation
- **AND** the klantmanager is notified that the beschikking must document the refusal

#### Scenario: Passing both tests grants benefit and creates a trajectory
- **GIVEN** both toetsen are `uitgevoerd = true`, vermogen is under threshold, and inkomen is under the bijstandsnorm
- **WHEN** the zaak is saved
- **THEN** `rechtOpBijstand` is set to `true`
- **AND** the status auto-transitions to `beschikking-voorbereiding`
- **AND** a `ReIntegratieTraject` object is created and linked

### Requirement: Automatic re-integratie-trajectory creation when income test passes
If the applicant qualifies for bijstand they are by law required to participate in re-integration, so the system MUST auto-create a `ReIntegratieTraject`; the `ReIntegratieTraject` entity carries `zaakId`, `klantmanagerId`, `startDatum`, `trajectSoort`, `afstandTotArbeidsmarkt`, `instrumenten`, `samenwerkendePartijen`, `evaluatieMomenten` (optional), `tegenprestatieVerplicht`, and `vrijstellingArbeidsverplichting` (optional).

#### Scenario: Qualifying case auto-creates a re-integration trajectory
- **GIVEN** the inkomenstoets results in `rechtOpBijstand = true`
- **WHEN** the zaak is saved
- **THEN** a `ReIntegratieTraject` is created with a `zaakId` reference
- **AND** `klantmanagerId` is set to the case behandelaar
- **AND** `startDatum` is set to the ingangsdatum (or shortly after beschikking issuance)
- **AND** the klantmanager must populate `trajectSoort` and `afstandTotArbeidsmarkt` within 2 weeks

### Requirement: Mandatory AVG classification with financiële category
Every Participatiewet case contains sensitive financial data; the `avgClassificatie` MUST be present and declare the financial category, driving the tightest access controls.

#### Scenario: Save is rejected without classification
- **GIVEN** a klantmanager saves a bijstandsaanvraag without `avgClassificatie`
- **WHEN** the save is triggered
- **THEN** the system rejects the save and requires the classification block

#### Scenario: Financial classification drives tight access and logging
- **GIVEN** the zaak is saved with `avgClassificatie.categorieen = ["financieel"]`
- **WHEN** the zaak is queried for access control
- **THEN** only the assigned klantmanager and wijkteam peers may see content
- **AND** all reads are logged

### Requirement: Access control limited to work-and-income team
Only the assigned klantmanager and their werk-en-inkomentteam MAY read zaak content; others MUST be blocked, with an FG-audit metadata-only mode.

#### Scenario: Out-of-team staff see metadata only
- **GIVEN** a bijstandsaanvraag is assigned to klantmanager-477 (werk-en-inkomentteam)
- **WHEN** a staff member from a different wijkteam queries the zaak
- **THEN** only metadata (zaak number, status, dates) is returned
- **AND** all financial and personal details are blocked

#### Scenario: FG audit mode returns metadata and auditLog only
- **GIVEN** a functionaris gegevensbescherming needs to audit the case
- **WHEN** they access in FG-audit mode
- **THEN** they receive metadata plus the auditLog without full financial data, flagged as "FG-audit"

### Requirement: Statutory retention (10 years post-closure) with deadline-driven destruction proposals
All Participatiewet cases MUST be retained for 10 years after closure, then proposed for destruction.

#### Scenario: Vernietigingsdatum is 10 years after closure
- **GIVEN** a `ParticipatiewetZaak` is closed on 2026-03-15
- **WHEN** `vernietigingsDatum` is calculated
- **THEN** it is set to 2036-03-15

#### Scenario: Destruction proposal generated near the deadline
- **GIVEN** the current date is within 30 days of a zaak's `vernietigingsDatum`
- **WHEN** the batch job runs
- **THEN** a `vernietigingsvoorstel` is generated for archivaris approval

### Requirement: Re-integratie-trajectory milestones and evaluatie scheduling
The system MUST manage re-integratie as an active, ongoing process with regular milestones and evaluaties.

#### Scenario: Quarterly milestone review task is created
- **GIVEN** a `ReIntegratieTraject` is active
- **WHEN** a quarterly evaluatie date is within 14 days
- **THEN** a task is created for the klantmanager to conduct a milestone review

#### Scenario: Ending wage subsidy notifies klantmanager and employer
- **GIVEN** a `ReIntegratieTraject` has an instrument with a 12-month loonkostensubsidie
- **WHEN** the end date approaches
- **THEN** both the klantmanager and the employer are notified the subsidy period is ending
- **AND** a next-step decision (extension, unsubsidized employment, or loop back to job-search) is required

### Requirement: Automatic anonymization of financial data on export
Participatiewet cases contain detailed financial information; exports MUST be anonymized via `pii-detection-masking` unless explicit consent exists.

#### Scenario: Export without consent is anonymized into bands
- **GIVEN** financial data from a bijstandsaanvraag is being exported and no toestemming record is found
- **WHEN** the export is triggered
- **THEN** `pii-detection-masking` replaces BSN with a pseudonym, exact income amounts with income-band ranges, vermogen with asset-band ranges, and employer/creditor names with generic placeholders

#### Scenario: Export with consent sends identified data
- **GIVEN** explicit toestemming exists (e.g., for tax-authority data-sharing in a re-integration case)
- **WHEN** the export proceeds
- **THEN** the system sends identified data and logs the consent basis

### Requirement: Comprehensive audit logging for financial-data access
Every read of sensitive financial data MUST be logged.

#### Scenario: Klantmanager read of financial fields is logged
- **GIVEN** a klantmanager opens a bijstandsaanvraag and views the vermogens- and inkomens-gegevens
- **WHEN** the zaak is displayed
- **THEN** the system logs `zaakId`, `medewerkerId`, `tijdstip`, `ipAdres`, and `geraadpleegdeVelden` (e.g., `vermogensToets`, `inkomensToets`)

#### Scenario: Third-party agency read is logged with purpose
- **GIVEN** a third-party agency (UWV, gemeente-accountant, tax authority) is granted read-access for a specific purpose
- **WHEN** they access the data
- **THEN** the system logs `zaakId`, `requestingOrganisatie`, `doelGroep`, `geautoriseerdeGegevens`, and `tijdstip`

### Requirement: Support for counter-services obligation (tegenprestatie) and exemptions
Participatiewet bijstandontvangers are obligated to participate in re-integratie unless exempt; the decision MUST be explicit and exemptions periodically reassessed.

#### Scenario: Tegenprestatie decision is explicit
- **GIVEN** a `ReIntegratieTraject` is created
- **WHEN** the klantmanager reviews the applicant's circumstances
- **THEN** they must explicitly set `tegenprestatieVerplicht = true` or record an exemption reason (medisch, kinderopvang, ouderschap, etc.)

#### Scenario: Medical exemption schedules periodic reassessment
- **GIVEN** `tegenprestatieVerplicht = false` with reason "medische arbeidsongeschiktheid"
- **WHEN** the zaak is saved
- **THEN** a recurring task reminder is created every 6 months to reassess whether the medical exemption still applies

### Requirement: Participatiewet seed data for exploration
The implementation chain MUST load three realistic Participatiewet seed cases (OpenRegister-backed) so testers can explore the zaaktype.

#### Scenario: Three representative Participatiewet cases are seeded
- **GIVEN** the Participatiewet register patch is applied with seed data
- **WHEN** a tester lists Participatiewet cases
- **THEN** a young-single-parent case with wage-subsidy re-integration (`zaak-2026-pw-01278`, alleenstaand-met-kinderen, vermogen under threshold, `rechtOpBijstand = true`, werkfit-maken, status `re-integratie-loopt`, werk-en-inkomentteam-oost, AVG financieel + gezinssituatie, 10-jaar bewaartermijn) is present
- **AND** an older-worker-from-sickness case (`zaak-2026-pw-02641`, age 58, top-up bijstand, scholing-specific, afstand zeer-groot, tegenprestatie verplicht, status `re-integratie-loopt`, AVG financieel + medisch) is present
- **AND** a recent-immigrant inburgering-linked case (`zaak-2026-pw-03502`, inburgering-gerelateerde-bijstand, werkfit-maken, language + credential recognition, status `beschikking-voorbereiding`, AVG financieel + gezinssituatie) is present

