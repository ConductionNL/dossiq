---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# dossiq-sociaal-domein-avg-consent Specification

## Purpose
Enforces AVG/GDPR-compliant handling of special-category personal data in sociaal-domein cases (WMO, Jeugdwet, Participatiewet). Every case must declare its data-category classification at creation, access is restricted to the case's wijkteam with data-driven guards, exports without recorded consent are automatically anonymized, and all reads are immutably audit-logged. It also tracks revocable citizen consent, statutory retention with archivaris-reviewed destruction, subject-access-requests, and data-breach incident reporting.

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

### Requirement: Another domain answers only that a case exists (REQ-XDV-01)

A cross-domain lookup SHALL return, for a person, whether an open case
exists in another domain, which domain it is and the contact for it. It
SHALL return nothing else: no content, no status, no dates and no case
number. The content of the other domain's case SHALL stay unreadable.

#### Scenario: A consulent learns that a household is known elsewhere
@e2e tests/e2e/the-social-domain-plan-and-its-grounds.spec.ts

- **GIVEN** a person with an open Jeugdwet case and a Wmo consulent looking them up
- **WHEN** the lookup runs
- **THEN** it SHALL answer that a case exists, that it is Jeugdwet, and who to contact
- **AND** it SHALL return no content of that case

#### Scenario: Nothing leaks through the projection
@e2e exclude unit over the projection; CrossDomainExistenceTest

- **GIVEN** the same lookup
- **WHEN** the returned fields are enumerated
- **THEN** they SHALL be exactly the existence, the domain and the contact

### Requirement: The lookup requires a ground, chosen first and logged (REQ-XDV-02)

A cross-domain lookup SHALL require an authorisation ground to be chosen
before it runs, and SHALL be refused without one. The ground, the person
looked up, the requester, the moment and what was returned SHALL be written
to `sociaalDomeinAuditLog` in the same act as the answer. The log SHALL
answer what was looked up about a person.

#### Scenario: No ground, no answer
@e2e tests/e2e/the-social-domain-plan-and-its-grounds.spec.ts

- **GIVEN** a consulent who has chosen no ground
- **WHEN** they try to look a person up across domains
- **THEN** the lookup SHALL be refused
- **AND** the refusal SHALL say that a ground is required

#### Scenario: The ground is written with the answer
@e2e exclude unit; SociaalDomeinAuditLogTest

- **GIVEN** a lookup performed on a chosen ground
- **WHEN** the log is read
- **THEN** it SHALL hold the ground, the person, the requester, the moment and what was returned
- **AND** the field `authorisationGround` SHALL be populated rather than declared and empty

#### Scenario: A person can be told what was looked up about them
@e2e tests/e2e/the-social-domain-plan-and-its-grounds.spec.ts

- **GIVEN** a person looked up twice in a year
- **WHEN** the log is read for that person
- **THEN** both lookups SHALL be returned with their grounds and their dates

### Requirement: A hand-off across organisations needs recorded consent (REQ-CST-01)

A hand-off or share of a case to another organisation SHALL be refused
unless a `toestemming` record covers this case, this receiving organisation
and this moment. The refusal SHALL name what is missing. A case type SHALL
be able to declare that consent is required for moves inside the
organisation as well.

#### Scenario: A Wmo case cannot leave without consent
@e2e tests/e2e/custody-and-handover-of-a-case.spec.ts

- **GIVEN** a Wmo case with no recorded consent
- **WHEN** a handler tries to hand it to a partner organisation
- **THEN** the hand-off SHALL be refused
- **AND** the refusal SHALL say that consent for this receiver is missing

#### Scenario: Consent that has lapsed does not cover the hand-off
@e2e exclude unit; CaseTransferConsentGateTest

- **GIVEN** a consent whose period ended last month
- **WHEN** the same hand-off is attempted
- **THEN** it SHALL be refused
- **AND** the refusal SHALL name the period that ended

#### Scenario: A move between teams of one organisation is not blocked
@e2e exclude unit; CaseTransferConsentGateTest

- **GIVEN** a case type that does not require consent inside the organisation
- **WHEN** the case moves from one team to another in the same organisation
- **THEN** the move SHALL proceed with no consent record

### Requirement: The recorded scope travels with the share (REQ-CST-02)

Consent SHALL name the scope it grants: which categories of the file the
receiving organisation may read, and for how long. The share created by the
hand-off SHALL carry that scope. A share SHALL NOT be created without one.

#### Scenario: The partner sees only what the consent names
@e2e tests/e2e/custody-and-handover-of-a-case.spec.ts

- **GIVEN** a consent granting the receiver the care plan and not the medical history
- **WHEN** the hand-off creates the share
- **THEN** the share SHALL carry that scope
- **AND** a share with no scope SHALL NOT be written

#### Scenario: The scope is readable afterwards
@e2e exclude unit; PartnerShareScopeTest

- **GIVEN** a case shared with a partner organisation
- **WHEN** the case's sharing is read
- **THEN** it SHALL name the consent, the scope and the period it runs for
