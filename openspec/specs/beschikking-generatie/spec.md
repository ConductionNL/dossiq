---
status: done
note: >-
  Implemented and archived 2026-06-13 (change beschikking-generatie). BeschikkingService/BeschikkingGenerationService/BeschikkingController + adapter interfaces (template/signing/archival) + Vue dialogs shipped. Three cross-app integrations remain deferred ([~]): Berichtenbox routing (openconnector), archival ingestion (openregister), PDF/A-3 template engine (docudesk) — each consumed via an adapter interface in dossiq.
---

# beschikking-generatie Specification

## Purpose
Composes a conceptbeschikking from a template + current zaakdata, drives the verleend/geweigerd outcome with prefilled motivation, renders a PDF, attaches it as a `bijlage`, and routes it to the addressee. Dossiq owns composition + workflow; PDF rendering, archival, and Berichtenbox delivery are integrated via adapter interfaces backed by docudesk/openregister/openconnector.

## Requirements

### Requirement: Conceptbeschikking vanuit zaakgegevens samenstellen (REQ-BES-001)

The system SHALL compose a conceptbeschikking from a template (Docudesk) and the current zaakdata (Dossiq), with all required fields prepopulated and missing required fields explicitly marked.

**Feature tier**: V1

#### Scenario: Conceptbeschikking is generated with prefilled fields

- **GIVEN** a WMO case with completed indicatiestelling and the handler clicks "beschikking opstellen"
- **WHEN** the system applies the template `tpl-wmo-toekenning-huishoudelijke-hulp-v4`
- **THEN** a conceptbeschikking SHALL be generated with `geadresseerde`, `omvang`, `ingangsdatum`, and `motivering` automatically filled from case data and indicatiestelling
- **AND** the HTTP response SHALL include the full Beschikking object with `huidigeStatus: ontwerp`

#### Scenario: Missing required fields are marked and block progression

- **GIVEN** a required field in the template cannot be filled from zaak data (e.g., motivering must be entered by hand)
- **WHEN** the conceptbeschikking is displayed
- **THEN** the missing field SHALL be visually marked as `_required: true` in the composed beschikking
- **AND** the system SHALL reject any state transition beyond `ontwerp` until the field is manually filled
- **AND** the API response code for a PATCH to `akkoord` without the field SHALL be HTTP 400 with a specific error message listing the missing field

#### Scenario: Composition request includes zaakId and returns templated PDF preview

- **GIVEN** the handler requests composition via `POST /api/beschikkingen` with `zaakId` and optional template overrides
- **WHEN** the request is processed
- **THEN** the system SHALL call Docudesk to render the template, passing all zaakdata as context
- **AND** the response SHALL include `samengesteldeInhoud.bestandId` (Nextcloud file ID), `checksumSha256`, and `paginas` count
- **AND** the system SHALL store the bestand in Nextcloud linked to the case

### Requirement: Mandaatverificatie for akkoordstap (REQ-BES-002)

Before a beschikking can transition from `ontwerp` to `akkoord-mandaat`, the system SHALL verify that the chosen approver is authorized under the current mandaatregeling for the beschikkingType and bedrag.

**Feature tier**: V1

#### Scenario: Non-authorized mandaatlevel is rejected

- **GIVEN** a beschikking for a €18,000 WMO toekenning
- **WHEN** a handler selects a consulent (mandaat-limit €5,000) as akkoordgever
- **THEN** the system SHALL reject the PATCH to `akkoord` with HTTP 403
- **AND** the response SHALL include a message that an afdelingsmanager (€25,000 limit) or directeur is required

#### Scenario: Authorized mandaatlevel is accepted and recorded

- **GIVEN** an afdelingsmanager with valid mandaat
- **WHEN** the handler submits PATCH `/api/beschikkingen/{id}/akkoord` with `akkoordDoor: afdelingsmanager-wmo-15`
- **THEN** the system SHALL verify the mandaat via the geldende mandaatregeling
- **AND** the state SHALL transition to `akkoord-mandaat`
- **AND** `mandaatGegeven` SHALL be recorded with regeling-id, niveau, actor, and timestamp
- **AND** a `StateMachineLog` entry SHALL be created

### Requirement: eIDAS-gekwalificeerde elektronische handtekening (REQ-BES-003)

Signing of a beschikking SHALL occur via an eIDAS-qualified Trust Service Provider (TSP), and the signing result SHALL produce a durably stored validation report.

**Feature tier**: V1

#### Scenario: Valid signature is recorded and beschikking transitions to ondertekend

- **GIVEN** the beschikking has status `akkoord-mandaat` and the handler initiates signing
- **WHEN** the TSP flow is invoked via OpenConnector
- **THEN** the handler SHALL authenticate with the TSP (e.g., via KPN or EvidosSign)
- **AND** the signed PDF bytes SHALL be returned with a `validatieRapportId`
- **AND** the system SHALL store the signed PDF in Nextcloud and link it to the beschikking
- **AND** `handtekening.ondertekeningTijdstip`, `certificaatSerienummer`, and `validatieRapportId` SHALL be recorded
- **AND** the state SHALL transition to `ondertekend`
- **AND** a `StateMachineLog` entry SHALL be created with `bewijsMateriaal.soort: tsp-handtekening-rapport`

#### Scenario: Invalid signature blocks transition

- **GIVEN** the TSP returns an invalid or expired certificate
- **WHEN** the system processes the TSP response
- **THEN** the state SHALL remain `akkoord-mandaat` (no transition)
- **AND** the error SHALL be logged with a warning to the handler
- **AND** the handler SHALL be prompted to retry

### Requirement: Berichtenbox-aanlevering met kanaalkeuze burger/bedrijf (REQ-BES-004)

Delivery of a signed beschikking to the addressee SHALL occur via the correct Berichtenbox channel: MijnOverheid for burgers (BSN), eHerkenning OIN for businesses, or print-post as fallback.

**Feature tier**: V1

#### Scenario: Burger with MijnOverheid is delivered via MijnOverheid

- **GIVEN** the addressee is a burger with BSN and MijnOverheid Berichtenbox activated
- **WHEN** the handler initiates delivery via PATCH `/api/beschikkingen/{id}/verzend`
- **THEN** OpenConnector SHALL submit the beschikking to the MijnOverheid API
- **AND** `verzending.berichtId` SHALL be recorded from the MijnOverheid response
- **AND** `verzending.verzondenOp` SHALL be set to the current timestamp
- **AND** the state SHALL transition to `verzonden`

#### Scenario: Bedrijf with OIN is delivered via eHerkenning

- **GIVEN** the addressee is a bedrijf with OIN
- **WHEN** delivery is initiated
- **THEN** OpenConnector SHALL submit the beschikking to the eHerkenning OIN Berichtenbox endpoint
- **AND** `verzending.berichtId` and `verzending.verzondenOp` SHALL be recorded

#### Scenario: No Berichtenbox → print-post fallback

- **GIVEN** the addressee has no Berichtenbox activated
- **WHEN** delivery is initiated
- **THEN** the system SHALL detect the absence and mark the beschikking for print-post
- **AND** a print-job SHALL be created for the postkamer
- **AND** `verzending.kanaal` SHALL be set to `print-post`
- **AND** the state SHALL transition to `verzonden`

### Requirement: State-machine voor beschikkingsstatus (REQ-BES-005)

The system SHALL enforce a formal state-machine with the sequence: `ontwerp` → `akkoord-mandaat` → `ondertekend` → `verzonden` → `ontvangen-bevestiging` → `gearchiveerd`. Every transition SHALL be logged with actor, timestamp, and evidence material.

**Feature tier**: V1

#### Scenario: Invalid state transition is rejected

- **GIVEN** a beschikking with status `ondertekend`
- **WHEN** an attempt is made to jump directly to `gearchiveerd`
- **THEN** the system SHALL reject the transition with HTTP 409
- **AND** the state SHALL remain `ondertekend`
- **AND** the error message SHALL list the allowed next states

#### Scenario: Every transition is logged

- **GIVEN** a beschikking transitions from one state to another
- **WHEN** the transition is processed
- **THEN** a `StateMachineLog` entry SHALL be created with:
  - `van`, `naar`, `tijdstip`, `actor`, `actorType`
  - `trigger` (handmatig | automatisch)
  - `bewijsMateriaal` (TSP-rapport-id, berichtId, etc.)

### Requirement: Bezwaartermijn-trigger op bekendmakingsdatum (REQ-BES-006)

The system SHALL automatically start a 6-week bezwaar term (per Awb art. 6:7) on the bekendmakingsdatum, schedule a reminder 1 week before expiry, and when a bezwaarschrift is received, automatically link it to the original beschikking.

**Feature tier**: V1

#### Scenario: Bezwaar-termijn is calculated and reminder scheduled

- **GIVEN** a beschikking is delivered with `bekendmakingDatum: 2026-04-02`
- **WHEN** the state transitions to `verzonden`
- **THEN** `bezwaarTermijnEindDatum` SHALL be calculated as `2026-05-14` (6 weeks later)
- **AND** `herinneringDatum` SHALL be set to `2026-05-07` (1 week before end)
- **AND** a `BezwaarTrigger` object SHALL be created with `archiefTriggerActief: true` and `archiefDatum: 2026-05-15`

#### Scenario: Received bezwaarschrift is linked to the decision

- **GIVEN** a bezwaarschrift is received and registered against a beschikking
- **WHEN** the bezwaar-system processes the input
- **THEN** the original beschikking's `bezwaarOntvangen` flag SHALL be set to `true`
- **AND** the `bezwaarZaakId` SHALL be recorded
- **AND** the archival trigger SHALL be disabled (to prevent archival while bezwaar is pending)

### Requirement: Archiefoverdracht met TMLO/MDTO-metadata (REQ-BES-007)

After the bezwaar term expires (or bezwaar is denied/withdrawn), the beschikking SHALL be automatically consolidated to an immutable archived copy and transferred to the archief (OpenRegister) with complete TMLO or MDTO metadata.

**Feature tier**: V1

#### Scenario: Beschikking is archived after bezwaar-term expiry

- **GIVEN** a beschikking with `bezwaarTermijnEindDatum: 2026-05-14` and no bezwaar received
- **WHEN** a daily batch job runs on 2026-05-15
- **THEN** the system SHALL call OpenRegister to ingest the beschikking
- **AND** TMLO-1.2 metadata SHALL be generated based on gemeente-config
- **AND** the state SHALL transition to `gearchiveerd`
- **AND** `archief.gearchiveerdOp`, `archief.archiefId`, and `archief.vernietigingsdatum` SHALL be recorded

#### Scenario: MDTO is used if configured for the gemeente

- **GIVEN** a gemeente is configured to use MDTO instead of TMLO
- **WHEN** the archival job runs
- **THEN** the system SHALL generate MDTO-format metadata instead of TMLO
- **AND** the metadata block SHALL be passed to OpenRegister

### Requirement: Niet-wijzigbare beschikking na ondertekening (REQ-BES-008)

A beschikking with status `signed` or later SHALL NOT be edited substantively; only process
events (delivery, receipt confirmation, bezwaar linking) are allowed.

The freeze SHALL be enforced at the persistence boundary, not in a single service method. Any
write that reaches the object store SHALL be refused, whichever route it arrived on: the
dossiq API, OpenRegister's generic object API, or an import. The stored state decides whether
a write is refused, never the incoming payload.

A refused write SHALL name the successor as the way forward, so a handler who is told no is
also told what to do instead.

**Feature tier**: V1

#### Scenario: Substantive edit is rejected after ondertekend

- **GIVEN** a beschikking with status `signed`
- **WHEN** an attempt is made to modify `rationale` or `decision`
- **THEN** the system SHALL reject the PATCH with HTTP 409
- **AND** the response SHALL include a message that a wijzigingsbeschikking or an
  intrekkingsbeschikking must be created instead

#### Scenario: The generic object API is refused the same edit

- **GIVEN** a beschikking with status `sent`
- **WHEN** a client writes `rationale` through OpenRegister's object API, bypassing the dossiq
  beschikking routes
- **THEN** the write SHALL be refused before the row is changed
- **AND** the stored beschikking SHALL be byte-identical to what it was before the attempt

#### Scenario: A signed beschikking cannot be deleted

- **GIVEN** a beschikking with status `archived`
- **WHEN** a delete is attempted through any route
- **THEN** the delete SHALL be refused before the row is removed

#### Scenario: Process events stay allowed after signing

- **GIVEN** a beschikking with status `signed`
- **WHEN** the dispatch record, the receipt confirmation or the bezwaar link is written
- **THEN** the write SHALL succeed
- **AND** the decision content SHALL be unchanged

#### Scenario: A draft is still editable

- **GIVEN** a beschikking with status `draft` or `approved-mandate`
- **WHEN** `rationale` is modified
- **THEN** the write SHALL succeed

#### Scenario: Wijzigingsbeschikking references the original

- **GIVEN** a handler decides to correct a signed beschikking
- **WHEN** they issue a wijzigingsbeschikking
- **THEN** a new beschikking SHALL be created that references the original
- **AND** the original SHALL remain signed and unmodified
- **AND** the successor SHALL follow REQ-BES-012 for its number and its chain pointers

### Requirement: Audit-bewijs voor juridische verificatie (REQ-BES-009)

The system SHALL provide an exportable audit-proof package containing all state transitions, mandaat references, TSP validation reports, delivery proofs, and receipt confirmations.

**Feature tier**: V1

#### Scenario: Audit-pakket is exported with all evidence

- **GIVEN** a request to export an audit-pakket for a beschikking
- **WHEN** the handler calls `GET /api/beschikkingen/{id}/audit-pakket` (download-endpoint)
- **THEN** the system SHALL generate a ZIP file containing:
  - The archived PDF (final version)
  - All `StateMachineLog` entries (JSON)
  - The `MandaatRegeling` object (state at time of akkoord)
  - The TSP validatierapport (referenced by ID)
  - Berichtenbox delivery proofs (berichtId, timestamps)
  - Any linked bezwaar-zaak ID
  - A manifest file describing the package contents
- **AND** the ZIP SHALL be cryptographically signed by Dossiq (PKCS#7)
- **AND** the response SHALL include `Content-Type: application/zip` with appropriate download headers

#### Scenario: eIDAS-signature is verifiable in audit-pakket

- **GIVEN** an audit-pakket for a beschikking
- **WHEN** the TSP validatierapport and the signed PDF are verified
- **THEN** the signature integrality SHALL be confirmed
- **AND** the TSP certificate chain SHALL be verifiable against the Europese Trust List at the time of signing

### Requirement: Templates versiebeheer met effectieve datum (REQ-BES-010)

Beschikking templates in Docudesk SHALL be versioned with an effective date (`ingangsdatum`). The composition of a beschikking SHALL always use the template version that was effective on its bekendmakingsdatum.

**Feature tier**: V1

#### Scenario: Correct template version is selected based on known date

- **GIVEN** template `tpl-wmo-toekenning-huishoudelijke-hulp` has:
  - version 3 with `ingangsdatum: 2025-01-01`
  - version 4 with `ingangsdatum: 2026-01-01`
- **WHEN** a beschikking is composed on 2026-04-01
- **THEN** version 4 SHALL be used (since 2026-04-01 ≥ 2026-01-01)

#### Scenario: Old beschikking re-issued uses original template version

- **GIVEN** a beschikking from 2025 is re-issued (corrected copy)
- **WHEN** the composition occurs with the original `bekendmakingDatum: 2025-06-15`
- **THEN** the system SHALL use template version 3 (valid in 2025), not version 4
- **AND** the beschikking text SHALL be consistent with the original

### Requirement: Data Model for Beschikking (REQ-BES-011)

The Dossiq register SHALL define the `beschikking`, `stateMachineLog`, `bezwaarTrigger`, and
`mandateArrangement` entities with all required properties, constraints, and relations per the
data model in design.md.

**Feature tier**: V1

#### Scenario: Beschikking entity is fully queryable

- **GIVEN** a Dossiq instance with seeded beschikkingen
- **WHEN** the system queries `GET /api/beschikkingen?currentStatus=signed`
- **THEN** the response SHALL include all signed beschikkingen with their full payloads

#### Scenario: Immutability of ondertekend beschikking is enforced at schema level

- **GIVEN** the `beschikking` schema, whose content fields must stay editable while the
  beschikking is a draft
- **WHEN** immutability is enforced
- **THEN** it SHALL be enforced by a guard that reads the stored `currentStatus` at write time
- **AND** it SHALL NOT be expressed as a `readOnly` property flag, because that freezes a
  property from creation onward and would make a draft unwritable

### Requirement: The template seam SHALL say whether a real renderer is behind it

`TemplateEngineAdapterInterface` SHALL be bound from the `beschikking_template_adapter`
app-config key, so an integrator can substitute a renderer without editing dossiq.
When no class is named and filinq is enabled, the seam SHALL bind
`FilinqTemplateEngineAdapter`. When no class is named and filinq is not enabled, the
seam SHALL bind `MockTemplateEngineAdapter` and SHALL log a translated warning that
tells the reader to install filinq. When an administrator names the mock, the seam
SHALL bind it and SHALL log that the mock was chosen. The Integrations page SHALL
carry a Document templates card that reads Live when filinq's adapter is bound and
Simulated when the mock is bound, decided by the same rule the seam uses.

The filinq probe SHALL resolve through `FleetAppId`, never a literal app id. Filinq
renamed from docudesk, both names are in the field, and a hardcoded lookup against the
wrong one returns false and takes the integration dark without erroring.

**Feature tier**: MVP

#### Scenario: Filinq absent, and the warning says to install it
@e2e exclude The binding is a DI-time decision with no browser surface; AdapterHonestyTest asserts the resolution and the vitest seed guard asserts the card.

- **GIVEN** an instance where filinq is not installed
- **WHEN** the template seam resolves
- **THEN** it SHALL bind the mock
- **AND** the warning SHALL tell the reader to install filinq, then name its adapter class

#### Scenario: Filinq present but no adapter named, and the warning says which
@e2e exclude Same absent surface; asserted by AdapterHonestyTest.

- **GIVEN** an instance where filinq is installed and enabled and `beschikking_template_adapter` is empty
- **WHEN** the template seam resolves
- **THEN** it SHALL bind `FilinqTemplateEngineAdapter`, not the mock
- **AND** no fallback warning SHALL be logged, and the Document templates card SHALL name filinq's adapter as the one that answers

#### Scenario: An administrator chose the mock
@e2e exclude Same absent surface; asserted by AdapterHonestyTest.

- **GIVEN** an instance where filinq is enabled and `beschikking_template_adapter` names `MockTemplateEngineAdapter`
- **WHEN** the template seam resolves
- **THEN** it SHALL bind the mock
- **AND** the warning SHALL say the mock was chosen and name the key to clear

#### Scenario: The card reads what the seam bound
@e2e exclude The card is drawn from IntegrationStatusService; a PHPUnit over the service asserts both words.

- **GIVEN** filinq enabled and `beschikking_template_adapter` empty
- **WHEN** an administrator opens the Integrations page
- **THEN** the Document templates card SHALL read Live
- **AND** with filinq disabled and the key still empty, the card SHALL read Simulated

### Requirement: The remedy clause is declared on the case type and printed (REQ-DEC-03)

A case type SHALL declare the remedy open against its decisions: the kind,
the term in days, and the body it is lodged with. The decision document
SHALL print that clause from the declaration and SHALL NOT take it from a
document template. Publishing a case type whose decisions carry no remedy
declaration SHALL warn, naming the case type.

#### Scenario: a besluit carries its bezwaarclausule
@e2e tests/e2e/decision-outcomes-on-the-case.spec.ts

- **GIVEN** a case type declaring bezwaar, 42 days, at the college
- **WHEN** a besluit is generated
- **THEN** the document SHALL print that kind, that term and that body

#### Scenario: a change in the law is one configuration change
@e2e tests/e2e/decision-outcomes-on-the-case.spec.ts

- **GIVEN** two case types sharing a document template
- **WHEN** one declares a different remedy term
- **THEN** its decisions SHALL print the new term
- **AND** the other's SHALL be unchanged

#### Scenario: a missing declaration is warned about

- **GIVEN** a case type whose decisions declare no remedy
- **WHEN** it is published
- **THEN** publication SHALL warn, naming the case type

### Requirement: Sending a decision starts the remedy term (REQ-DEC-04)

Sending a decision SHALL bind a term instance of kind `remedy`, from the
case type's declared remedy term, clocked on the administered working
calendar. Whether a decision is still open to a remedy SHALL be answerable
from the case without arithmetic.

#### Scenario: the bezwaartermijn starts when the besluit goes out
@e2e tests/e2e/decision-outcomes-on-the-case.spec.ts

- **GIVEN** a case type declaring a remedy term of 42 days
- **WHEN** the besluit is sent
- **THEN** a remedy term SHALL be bound, ending 42 working days later

#### Scenario: is this still open to bezwaar
@e2e tests/e2e/decision-outcomes-on-the-case.spec.ts

- **GIVEN** a decision sent 50 days ago with a 42 day remedy term
- **WHEN** a handler opens the case
- **THEN** it SHALL read that the remedy term has expired

### Requirement: A schema a service resolves SHALL have a configured key

Every OpenRegister schema that dossiq code resolves through an appconfig key SHALL have that
slug registered in the schema slug map and that key in the settings allowlist. A key nothing
writes leaves the service that reads it dead, and a dead service reports no error until a user
calls it.

**Feature tier**: V1

#### Scenario: Every resolved schema key is reconciled

- **WHEN** the schema key reconciler runs after the register is imported
- **THEN** every appconfig key that app code passes to the config resolver SHALL have been
  written with a live schema id

#### Scenario: The beschikking lifecycle resolves its four schemas

- **GIVEN** a Dossiq instance with the register imported
- **WHEN** a beschikking, a state machine log entry, a bezwaar trigger or a mandate
  arrangement is saved
- **THEN** the save SHALL resolve its schema and succeed
- **AND** it SHALL NOT fail with a not-configured error

### Requirement: A correction is a numbered successor, never an edit (REQ-BES-012)

A handler who must correct a signed beschikking SHALL do so by issuing a new beschikking that
references the one it replaces. The original SHALL remain exactly as it was served.

The successor SHALL carry its own reference number, distinct from the original's, and SHALL
record which beschikking it replaces. The original SHALL record which beschikking replaced it,
so the chain reads in both directions without a query.

A beschikking that has already been replaced SHALL NOT be replaced a second time. The chain is
linear: the correction of a correction succeeds the correction, not the original.

Every beschikking SHALL be numbered when it is composed, as the document series `beschikking`
of the generic capability `numbered-document-series` (REQ-NDS-001 to REQ-NDS-003, decisions 167
and 182): one running number per organisation per calendar year, `B-<year>-<six digits>` unless
the case type configures another prefix. When no number can be reserved the beschikking SHALL NOT
be composed, and the refusal SHALL say the request can be retried.

Who may issue a successor is whoever may change the case the beschikking belongs to.

**Feature tier**: V1

#### Scenario: A correction is issued as a successor

- **GIVEN** a beschikking with status `sent` and reference `B-2026-000123`
- **WHEN** a handler issues a correction
- **THEN** a new beschikking SHALL be created with `decisionType: amendment`
- **AND** the new beschikking SHALL record the original's id
- **AND** the new beschikking SHALL carry its own reference, the next number of its
  organisation's year, distinct from `B-2026-000123`
- **AND** the original SHALL still read `sent`, with its content unchanged

#### Scenario: The original points forward to its successor

- **GIVEN** a beschikking that has been replaced by a correction
- **WHEN** the original is read
- **THEN** it SHALL name the beschikking that replaced it
- **AND** that pointer SHALL be the only field the freeze allows a successor to write on it

#### Scenario: A withdrawal is a successor too

- **GIVEN** a signed beschikking a handler must withdraw
- **WHEN** an intrekkingsbeschikking is issued
- **THEN** it SHALL be created with `decisionType: withdrawal` and reference the original
- **AND** the original SHALL remain readable in the form it was served

#### Scenario: A superseded beschikking cannot be superseded twice

- **GIVEN** a beschikking that already names a successor
- **WHEN** a second correction of that same beschikking is attempted
- **THEN** the attempt SHALL be refused
- **AND** the refusal SHALL name the successor to correct instead

#### Scenario: A draft is changed, not succeeded

- **GIVEN** a beschikking with status `draft`
- **WHEN** a handler tries to issue a correction of it
- **THEN** the attempt SHALL be refused with HTTP 409
- **AND** the refusal SHALL say to change the draft instead

#### Scenario: Each organisation numbers its own year

- **GIVEN** organisation A has issued `B-2026-000041` and organisation B has issued `B-2026-000007`
- **WHEN** a beschikking is composed on a case of organisation A
- **THEN** it SHALL be numbered `B-2026-000042`
- **AND** organisation B's next beschikking SHALL be numbered `B-2026-000008`
- **AND** on 1 January the next beschikking of either SHALL be numbered `B-<new year>-000001`

#### Scenario: No number, no beschikking

- **GIVEN** the counter cannot be reached
- **WHEN** a beschikking is composed
- **THEN** nothing SHALL be saved
- **AND** the response SHALL be HTTP 503, naming the rule `document-number-unavailable`

#### Scenario: Only a case handler may issue a successor

- **GIVEN** a user without mutation access on the case of a signed beschikking
- **WHEN** they request a correction of it
- **THEN** the request SHALL be refused with HTTP 403
- **AND** no beschikking SHALL be composed
