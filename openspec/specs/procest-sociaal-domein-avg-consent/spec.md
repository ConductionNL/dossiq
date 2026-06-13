# procest-sociaal-domein-avg-consent Specification

## Purpose
TBD - created by archiving change sociaal-domein-zaaktypes. Update Purpose after archive.
## Requirements
### Requirement: Mandatory AvgClassificatie block at zaak creation
Every sociaal-domein zaak MUST declare its special-category data scope via an embedded `AvgClassificatie` value-type before creation is allowed. The value-type carries `categorieen` (array of `medisch`/`gezinssituatie`/`financieel`/`justitieel`/`etnisch`/`religieus`/`politieke-overtuiging`), `bijzonderePersoonsgegevens` (auto-flag), `rechtvaardiging` (AVG 9.2 exemption code), `rechtvaardigingToelichting`, `bewaarTermijnJaren` (WMO 15 / Jeugdwet 20 / Participatiewet 10), `vernietigingDatum` (auto-calculated), `toegangsBeperking`, `anonimiseringBijDelen`, and `exportBeperking`.

#### Scenario: Save is rejected without a classification block
- **GIVEN** a WMO-consulent creates a new zaak
- **WHEN** they attempt to save without an `avgClassificatie` block
- **THEN** the system rejects the save with a validation error: "AVG-classificatie is verplicht. Vul in welke gegevenscategorieën in deze zaak worden verwerkt."

#### Scenario: Selecting a category auto-populates derived fields
- **GIVEN** the consulent fills in `categorieen = ["medisch"]`
- **WHEN** they save
- **THEN** `bijzonderePersoonsgegevens` is set to `true`
- **AND** `bewaarTermijnJaren` is set to the zaaktype default (15 WMO / 20 Jeugdwet / 10 Participatiewet)
- **AND** `vernietigingDatum` is computed as the zaak closure date plus `bewaarTermijnJaren`
- **AND** the consulent is prompted to select a `rechtvaardiging` (AVG 9.2 exemption) and provide `rechtvaardigingToelichting`

### Requirement: Wijkteam-only access control with data-driven guards
Access to zaak content MUST be enforced at query time by comparing `zaak.wijkteam` to the requesting user, not by role alone, with a `tweedeBehandelaarId` override and an FG-audit metadata-only mode.

#### Scenario: Out-of-team query returns metadata only and is logged
- **GIVEN** a zaak has `wijkteam = wijkteam-zuid` and `toegangsBeperking = alleen-behandelaar-en-wijkteam`
- **WHEN** a staff member from `wijkteam-noord` queries the zaak
- **THEN** the query layer checks `user.wijkteam` against `zaak.wijkteam`
- **AND** on no match it returns only `zaakNumber`, `status`, `behandelaarId`, `aanvraagDatum`, and `deadlineDate`
- **AND** content fields (`ondersteuningsvraag`, `indicatiestelling`, `gezinsplan`, `vermogenstoets`, etc.) are blocked
- **AND** the attempt is logged with `resultaat = geweigerd-geen-toegang`

#### Scenario: Second handler overrides wijkteam membership
- **GIVEN** a staff member is recorded as `tweedeBehandelaarId` on the zaak
- **WHEN** they query it
- **THEN** the query layer grants full access regardless of wijkteam membership

#### Scenario: FG audit mode returns metadata plus auditLog
- **GIVEN** a functionaris gegevensbescherming queries a zaak with intent "audit"
- **WHEN** the query is made
- **THEN** metadata plus the `auditLog` is returned and all content fields are blocked
- **AND** the read is logged with `autorisatieGrond = fg-audit-override` and `resultaat = fg-audit-mode-metadata-only`

### Requirement: Automatic anonymization on export without recorded consent
If data is exported (API, openconnector, reporting) to an external party without a `Toestemming` record, PII MUST be auto-masked via `pii-detection-masking`.

#### Scenario: Unconsented export is anonymized
- **GIVEN** a zaak export is triggered (e.g., openconnector to a zorgaanbieder) and no toestemming record is found
- **WHEN** the export runs
- **THEN** `pii-detection-masking` replaces BSN with a pseudonym, geboortedatum with an age-group, exact amounts with ranges, clinical diagnoses with functional-impact summaries, family names with roles, and named organizations with generic labels

#### Scenario: Consented export sends identified data and logs the basis
- **GIVEN** a toestemming record exists for the target organization
- **WHEN** the export is triggered
- **THEN** fully identified data is sent
- **AND** the export is logged with `autorisatieGrond = toestemming` and the toestemming reference

### Requirement: Toestemming tracking with revocation support
External access to zaak content MUST require explicit, revocable citizen/parent consent recorded as a `Toestemming` entity carrying `zaakId`, `verleendDoorBsn`, `verleendDoorNaam`, `verleendDatum`, `geldigTot` (optional), `intrekkingMogelijk`, `ingetrokken`, `scope`, `tePartijen`, `tegegevens`, `tedoel`, `vastgelegdViaKanaal`, and `bewijsBestandId` (optional).

#### Scenario: Sharing checks for a consent record first
- **GIVEN** a jeugdconsulent wants to share a gezinsplan with a school during an MDO
- **WHEN** the consulent prepares to share
- **THEN** the system first checks whether a toestemming record exists for `tePartij = "school"`

#### Scenario: Sharing without consent warns and anonymizes
- **GIVEN** no toestemming exists
- **WHEN** the consulent proceeds anyway
- **THEN** a warning is shown: "Geen toestemming voor gegevensdeling met [school]. Gegevens worden geanonimiseerd."
- **AND** the share is logged with `resultaat = geanonimiseerd`

#### Scenario: Revocation makes future shares anonymized
- **GIVEN** a toestemming record exists and the citizen later revokes it
- **WHEN** they choose "Intrekken" in their consent panel
- **THEN** `toestemming.ingetrokken` is set to `true` and the revocation is logged
- **AND** future exports to that party are treated as if no toestemming exists (auto-anonymize)
- **AND** a follow-up task is created for the caseworker to review what data is currently shared

### Requirement: Comprehensive audit logging of all data access
Every read-action on special-category data MUST create an immutable `AuditLog` entry (in openregister's immutable auditTrail or a dedicated sociaal-domein auditLog) capturing `zaakId`, `medewerkerId`, `organisatie`, `actie`, `tijdstip`, `ipAdres`, `geraadpleegdeVelden`, `autorisatieGrond`, and `resultaat`.

#### Scenario: Internal read writes a complete log entry
- **GIVEN** a WMO-consulent opens a zaak with `categorieen = ["medisch"]`
- **WHEN** the zaak is displayed
- **THEN** an audit-log entry is created with `zaakId`, `medewerkerId`, `organisatie = gemeente`, `actie = read`, `tijdstip`, `ipAdres`, `geraadpleegdeVelden`, `autorisatieGrond = roltoewijzing`, and `resultaat = succes`

#### Scenario: External provider read under consent is logged
- **GIVEN** an externe zorgaanbieder is given read-access to a gezinsplan via openconnector under toestemming
- **WHEN** they access the data
- **THEN** the entry records `zaakId`, `medewerkerId = null`, `organisatie = Jeugdzorg-West`, `actie = read`, `tijdstip`, `ipAdres`, `geraadpleegdeVelden = ["gezinsplan", "evaluatie-momenten"]`, `autorisatieGrond = openconnector-sharing`, and `resultaat = succes`

### Requirement: Statutory retention with deadline-driven destruction proposals
Every zaak's destruction deadline MUST be tracked and reviewed by the archivaris before actual deletion; there is no silent deletion.

#### Scenario: Vernietigingsdatum is computed at closure
- **GIVEN** a WmoZaak is closed on 2026-03-15 with `bewaarTermijnJaren = 15`
- **WHEN** the zaak is saved
- **THEN** `vernietigingDatum` is set to 2041-03-15

#### Scenario: Approaching deadline generates an archivaris proposal
- **GIVEN** it is now 2041-02-20 (within 30 days of the destruction deadline)
- **WHEN** the daily batch job runs
- **THEN** a `vernietigingsvoorstel` task is generated for the gemeente archivaris with a notification
- **AND** the archivaris can approve destruction or request an uitzonderingsgrond for extended retention

#### Scenario: Approved destruction is executed and logged
- **GIVEN** the archivaris approves destruction
- **WHEN** the deadline date passes
- **THEN** the zaak is flagged `archiveStatus = destroyed` (or deleted per gemeente policy)
- **AND** the destruction is logged with `actie = delete`, timestamp, and the archivaris approval reference

### Requirement: Subject-access-request (burgerrecht) support
Citizens have the right (AVG art. 15) to a copy of all data held about them; the system MUST generate these reports.

#### Scenario: SAR produces a comprehensive plain-Dutch report
- **GIVEN** a citizen submits a subject-access-request to the gemeente
- **WHEN** the FG processes the request
- **THEN** the system queries all zaakken (WMO/Jeugdwet/Participatiewet) for that BSN, retrieves all related entities (Indicatiestelling, Gezinsplan, ReIntegratieTraject, MdoOverleg, Toestemming), all attached documents, and the complete auditLog
- **AND** it generates a plain-Dutch report PDF organized chronologically and by category
- **AND** all documents and log entries are marked with the SAR reference for tracking

### Requirement: Data breach (incident) reporting support
If a breach occurs it MUST be documented as an `AvgIncident` and, where required, reported to the Autoriteit Persoonsgegevens (AP) within 72 hours (GDPR art. 33).

#### Scenario: Breach creates an incident record and notification task
- **GIVEN** a data breach is discovered (e.g., a stolen laptop containing unencrypted zaakken data)
- **WHEN** the incident is logged
- **THEN** an `AvgIncident` record is created with `incidentDatum`, `oorzaak`, and `gegevensImpact`
- **AND** the system assesses whether GDPR art. 33 AP-notification is required (encryption status, data scope, number of affected citizens)
- **AND** if required, `meldingAp` is set to `true` and a 72-hour notification task is created for the DPA
- **AND** a breach-impact summary is generated for gemeente leadership

