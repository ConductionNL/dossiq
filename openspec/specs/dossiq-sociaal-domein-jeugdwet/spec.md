---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# dossiq-sociaal-domein-jeugdwet Specification

## Purpose
Provides the Jeugdwet (`jeugdwet-melding`) zaaktype for municipal youth-care casework, with a family-centered status lifecycle, gezinsplan drafting and consent workflow, and Multi-Disciplinary Overleg coordination. Because every case concerns minors, it enforces mandatory AVG classification, jeugdteam-scoped access with logged child-safety overrides, automatic anonymization of unconsented minutes, 20-year statutory retention, comprehensive audit logging, and subject-access-request support for both the child and parents.

## Requirements

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

### Requirement: A case plan holds interventions with a goal, a provider and dates (REQ-CPN-01)

A case plan SHALL hold interventions. Each intervention SHALL name what it
is, the goal it serves, its provider, its start date, its target date, its
state and its outcome. Several interventions SHALL be able to serve one
goal. A goal SHALL name what would count as met. A plain string SHALL NOT
stand in for a goal or an intervention.

#### Scenario: A plan with two goals and three interventions
@e2e tests/e2e/the-social-domain-plan-and-its-grounds.spec.ts

- **GIVEN** a household with a case plan
- **WHEN** two goals are set and three interventions are planned against them
- **THEN** each intervention SHALL name its goal, its provider and its target date
- **AND** one goal SHALL be able to carry two of the interventions

#### Scenario: An overdue intervention is visible as overdue
@e2e tests/e2e/the-social-domain-plan-and-its-grounds.spec.ts

- **GIVEN** an intervention whose target date has passed and which is not complete
- **WHEN** the plan is opened
- **THEN** the intervention SHALL be shown as overdue

#### Scenario: The provider is a party, not a name typed twice
@e2e exclude unit; InterventionProviderTest

- **GIVEN** an intervention with a provider
- **WHEN** the provider is read
- **THEN** it SHALL be a party in the platform's contact model with its contact details

#### Scenario: A goal can be evaluated
@e2e exclude unit; CasePlanGoalTest

- **GIVEN** a goal naming what would count as met
- **WHEN** a review records that it was met
- **THEN** the goal SHALL be closed with that observation
- **AND** a goal naming nothing measurable SHALL be refused on save

### Requirement: The existing plan text is migrated, not discarded (REQ-CPN-02)

Every string in `gezinsplan.goals` and `deploymentTrajectories` SHALL be
migrated into a goal or an intervention with its text intact, its unmapped
fields empty and its origin marked. No string SHALL be dropped and no value
SHALL be invented for a field the old shape did not carry.

#### Scenario: An old goal survives the migration
@e2e exclude unit; CasePlanStringMigrationTest

- **GIVEN** a gezinsplan with four goal strings and two trajectory strings
- **WHEN** the migration runs
- **THEN** six records SHALL exist carrying those exact texts
- **AND** each SHALL be marked as migrated from the old shape
- **AND** no provider or target date SHALL have been invented

### Requirement: A plan is reviewed, and the review is recorded (REQ-CPN-03)

A case plan SHALL carry a review date. A review SHALL record who reviewed it
and what changed. A plan whose review date has passed SHALL be visible as
due for review.

#### Scenario: A stale plan says it is stale
@e2e tests/e2e/the-social-domain-plan-and-its-grounds.spec.ts

- **GIVEN** a plan whose review date passed last month
- **WHEN** the case is opened
- **THEN** the plan SHALL be shown as due for review

#### Scenario: A review records what changed
@e2e exclude unit; CasePlanReviewTest

- **GIVEN** a plan under review
- **WHEN** one goal is closed and one intervention is added
- **THEN** the review SHALL record both changes, the reviewer and the date

### Requirement: A Jeugdwet report is a case of the Melding Jeugdwet case type (REQ-JW-CT-001)

The `jeugdwet` package SHALL install on every instance and its case type `melding-jeugdwet` SHALL be
the one Jeugdwet case type (no second one): category Jeugdhulp (the Jeugdwet domain, and still a
category the family-plan panel shows for), handled by `dossiq-sociaal-domein`, with statuses for
every status of the old `jeugdwetZaak` lifecycle (Jeugdhulp loopt, Evaluatie and Verlenging
aangevraagd added) and the record's data as fields. The support question, the family plan and
case meeting references and a supervision order SHALL be fields flagged `sensitive`.

#### Scenario: The young person's BSN stays restricted
- **GIVEN** the old record restricted `jeugdigeBsn` to dossiq-sensitive
- **THEN** on the case it SHALL be `subjectBsn`, restricted to the same group

### Requirement: Stored Jeugdwet reports move onto cases without loss or duplicates (REQ-JW-CT-002)

On upgrade every stored `jeugdwetZaak` SHALL become one case per case number on its mapped status,
with the young person's BSN as `subjectBsn`, the referrer as initiator and the youth worker as
assignee. Family plans (`gezinsplan.caseId`), consents (`toestemming.caseId`) and case meetings
(`mdoOverleg.caseIds`, a list) pointing at the record SHALL point at the case, so the family plan
shows on the case. The record SHALL be left as it was apart from `migratedTo` and `migratedAt`
(`jeugdwetZaak` 1.3.0).

#### Scenario: The family plan follows the report
- **GIVEN** a family plan whose caseId is a stored Jeugdwet report
- **WHEN** the report moves
- **THEN** the plan's caseId SHALL be the new case

### Requirement: A Jeugdwet report follows a flow (REQ-JW-CT-003)

The Melding Jeugdwet case type SHALL name a published flow from Melding ontvangen through Onderzoek,
Indicatie, Overleg and Besluit to Jeugdhulp loopt, Evaluatie, Verlenging aangevraagd and Afgehandeld.
The move to Indicatie SHALL require the support question and the move to Besluit the family plan,
both checked as the system so the mover need not read them.

#### Scenario: Every status can be reached
- **GIVEN** the shipped flow
- **THEN** every status of the case type SHALL be reachable from Melding ontvangen

### Requirement: A Jeugdwet report runs on an eight-week decision term (REQ-JW-CT-004)

Ruben (23 Oct 2026): a statutory term definition (Awb 4:13 lid 2, the Jeugdwet setting no decision
term of its own) SHALL bind to Melding Jeugdwet for eight weeks, without extension, and the record
import SHALL carry the term of a report still before its decision onto its case.

#### Scenario: A migrated report before its decision is tracked
- **GIVEN** a stored report in the family-plan stage
- **WHEN** it moves onto a case
- **THEN** the case SHALL carry a running statutory term ending eight weeks after the request date

### Requirement: A write on a family plan needs the right to change its case

Saving a goal, closing a goal, saving an intervention and recording a review SHALL check, before anything is written,
that the caller may change the case the plan (or the goal) belongs to. A write that names an existing goal or
intervention by id SHALL also need the right to change that record's case. A plan without a case SHALL NOT be written.

#### Scenario: A caller without access to the case

- **GIVEN** a family plan of case A and a signed-in user who may not change case A
- **WHEN** they `POST /api/family-plans/{planId}/goals`, `/interventions` or `/reviews`, or close one of its goals
- **THEN** the answer is 403
- **AND** nothing is written

#### Scenario: Another family's goal named by id

- **GIVEN** a user who may change case A but not case B
- **WHEN** they save a goal on case A's plan with the `id` of a goal on case B's plan
- **THEN** the answer is 403 and case B's goal is unchanged

### Requirement: A family plan is read only by whoever may read its case

`GET /api/family-plans/{planId}` SHALL answer the plan only when the caller may read the case the plan points at, and
`GET /api/cases/{caseId}/family-plan` only when the caller may read that case, checked before any plan, goal or
intervention is read. A plan without a case SHALL NOT be answered.

#### Scenario: A caller without access to the case

- **GIVEN** a family plan of case A and a signed-in user who may not read case A
- **WHEN** they ask `/api/family-plans/{planId}` or `/api/cases/A/family-plan`
- **THEN** the answer is 403 and holds no plan, goal or intervention

### Requirement: An intervention serves a goal of its own plan

Saving an intervention that names a `goal` SHALL be refused with 404 before anything is written when that goal is not
there, belongs to another plan, or belongs to a case rather than to this plan. The goal written is the goal checked.

#### Scenario: Another plan's goal

- **GIVEN** a user who may change cases A and B
- **WHEN** they save an intervention on case A's plan naming a goal of case B's plan
- **THEN** the answer is 404 and nothing is written
