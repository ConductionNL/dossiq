---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# dossiq-sociaal-domein-participatiewet Specification

## Purpose
Provides the Participatiewet (`bijstandsaanvraag`) zaaktype for municipal social-assistance casework, enforcing a mandatory vermogens- and inkomenstoets before any benefit decision and auto-creating a re-integration trajectory when an applicant qualifies. It manages an income-focused status lifecycle, work-and-income-team access control, mandatory AVG financial classification, anonymized financial exports, audit logging, counter-services (tegenprestatie) decisions with periodic exemption review, and 10-year statutory retention with destruction proposals.

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

### Requirement: A re-integration trajectory is a case of the Re-integratietraject case type (REQ-PW-RI-001)

dossiq SHALL ship the case type `re-integratietraject` as an automatically installed package,
handled by the group `dossiq-sociaal-domein`, confidential, with the statuses Aangemeld, Plan van
aanpak, In uitvoering, Evaluatie, Afgerond and Beëindigd, and the trajectory's data as its fields.
A trajectory SHALL NOT move to In uitvoering before its kind and its distance to the labour market
are answered, and SHALL NOT close as Afgerond without a result.

#### Scenario: The case type carries the trajectory's data
- **GIVEN** the shipped package
- **THEN** every field the trajectory record held SHALL be a case field or a field of the case type
- **AND** the exemption fields SHALL be hidden on the create form

### Requirement: Stored trajectories move onto cases without loss (REQ-PW-RI-002)

On upgrade, every stored `reIntegratieTraject` SHALL become exactly one case of the type, in In
uitvoering, with its klantmanager as assignee, its start date, its uuid as `externalReference`
and every other field answered on the case. The trajectory SHALL be left as it was apart from
`migratedTo` and `migratedAt`.

#### Scenario: A shipped trajectory maps onto a case
- **GIVEN** a trajectory with instruments, parties, evaluation moments and an exemption
- **WHEN** the case type's import maps it
- **THEN** the case SHALL carry each value, lists as JSON, the exemption as three answers

### Requirement: Re-integration trajectories are a view of the case list (REQ-PW-RI-003)

The Cases list SHALL offer the default view `re-integration-pathways`, the cases of the type
`re-integratietraject`.

#### Scenario: The view lists the trajectories
- **WHEN** a user opens `/cases?savedView=default:re-integration-pathways`
- **THEN** the list SHALL show the cases of the type, newest start first

### Requirement: The package ships an example trajectory (REQ-PW-RI-004)

On an instance that opted into example data, the package SHALL write one trajectory in flight as
a case of the type, answering only the type's fields and tied to the shipped Participatiewet case
`zaak-2026-pw-01278`. The demo data SHALL NOT carry placeholder `reIntegratieTraject` rows.

#### Scenario: The example reads as a real trajectory
- **GIVEN** an instance with example data
- **WHEN** the package installs
- **THEN** one case of the type SHALL be In uitvoering with its klantmanager, instruments and benefit case

### Requirement: Old re-integration addresses lead to the cases (REQ-PW-RI-005)

The re-integration menu entry SHALL open the Cases view `re-integration-pathways`.
`/settings/re-integratietrajecten` SHALL redirect to that view, and
`/settings/re-integratietrajecten/{id}` SHALL open the case whose `externalReference` is `{id}`,
or the view when no case carries it or the lookup fails.

#### Scenario: An old trajectory link opens its case
- **GIVEN** a trajectory `traj-1` moved onto case `case-9`
- **WHEN** a user opens `/settings/re-integratietrajecten/traj-1`
- **THEN** the case page of `case-9` SHALL open

#### Scenario: The old list address opens the view
- **WHEN** a user opens `/settings/re-integratietrajecten`
- **THEN** the Cases list SHALL open on the view `re-integration-pathways`

### Requirement: Re-integration is a default view like Woo, with no menu entry (REQ-PW-RI-006)

Ruben (23 Oct 2026): "Re-integratie trajecten should be a view like Woo." The re-integration menu
entry SHALL go; `re-integration-pathways` SHALL be a secondary default view of Cases with an icon and
a description, the mechanism the Woo requests view uses. Who sees re-integration cases follows case
access; the view carries no gate of its own. The old addresses keep redirecting (REQ-PW-RI-005).

#### Scenario: The view sits beside Woo's
- **WHEN** a user opens the more default views on Cases
- **THEN** "Re-integratietrajecten" SHALL be listed there with its icon and description, as "Woo-verzoeken" is

### Requirement: A benefit application is a case of the Bijstandsaanvraag case type (REQ-PW-CT-001)

dossiq SHALL ship the case type `bijstandsaanvraag` as an automatically installed package in the
category Werk en inkomen (the Participatiewet domain), handled by `dossiq-sociaal-domein`,
confidential, with a status for every status of the old `participatiewetZaak` lifecycle. The
household situation and the asset and income tests SHALL be case-type fields flagged `sensitive`.

#### Scenario: Restricted data stays restricted
- **GIVEN** the old record restricted `bsn`, `householdsSituation`, `equityAssessment` and `incomeAssessment` to dossiq-sensitive
- **THEN** on the case they SHALL be `subjectBsn` and `sensitiveProperties`, restricted to the same group

### Requirement: Stored applications move onto cases without loss or duplicates (REQ-PW-CT-002)

On upgrade every stored `participatiewetZaak` SHALL become a case of the type on its mapped status:
the BSN as `subjectBsn`, the klantmanager as assignee, the request date as start, the case number
as a former reference, every other field answered on the case. Two records with one case number
SHALL become one case, the second reported as a duplicate. Consents (`toestemming.caseId`) and
re-integration trajectories (`reIntegratieTraject.caseId`) pointing at the record SHALL point at
the case. The record SHALL be left as it was apart from `migratedTo` and `migratedAt`
(`participatiewetZaak` 1.3.0), and SHALL answer the cross-domain lookup only through its case.

#### Scenario: A shipped application maps onto a case
- **GIVEN** each of the three shipped applications
- **WHEN** the import maps it
- **THEN** its case SHALL carry the subject BSN, the klantmanager, the former case number, the sensitive answers apart and the ordinary ones in `properties`

### Requirement: A benefit application runs on an eight-week decision term (REQ-PW-CT-003)

The Bijstandsaanvraag case type SHALL declare an eight-week processing deadline, and a statutory
term definition (Awb 4:13 lid 2, the Participatiewet setting no term of its own) SHALL bind to it,
so a new application is tracked like any case with a statutory term. On import, an application
still before its decision SHALL carry its term onto the case from its request date; an application
past its decision SHALL have its term completed. The term definitions SHALL be seeded before the
import runs.

#### Scenario: A migrated application before its decision is tracked
- **GIVEN** a stored application in decision preparation, requested on 2 February
- **WHEN** it moves onto a case
- **THEN** the case SHALL carry a running statutory term ending eight weeks after 2 February
