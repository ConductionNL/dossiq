# ADR-000: Data Model — Procest

**Status:** accepted
**Standard:** CMMN 1.1 (OMG) + Schema.org + ZGW API mapping
**Storage:** OpenRegister (JSON object store, no own tables)
**Entities:** 39 schemas across 7 groups

## Context

Procest is a case management (zaakgericht werken) app for Nextcloud. It follows the
**thin-client pattern**: the app owns no database tables. All data is stored as
OpenRegister objects, validated against schemas defined in `lib/Settings/procest_register.json`.

The data model uses a layered standards approach:

| Layer | Standard | Purpose |
|-------|----------|---------|
| Primary (storage) | CMMN 1.1 concepts + Schema.org vocabulary | International case model |
| Semantic | Schema.org JSON-LD | Type annotations for linked data |
| API mapping | ZGW/RGBZ field names | Dutch government interoperability |
| Type system reference | ZGW Catalogi API (ZaakType) | Case type behavioral controls |
| Nextcloud native | Calendar, Files, Activity, Talk | Reuse where possible |

**Design principle:** International standards first. ZGW/RGBZ is an API mapping layer,
not the storage model. This makes Procest usable outside the Netherlands while remaining
interoperable with Dutch government systems.

OpenRegister built-in fields (NOT listed in tables below, always available on every entity):
`id`, `uuid`, `uri`, `version`, `createdAt`, `updatedAt`, `owner`, `organization`,
`register`, `schema`, `relations`, `files`, `auditTrail`, `notes`, `tasks`, `tags`,
`status`, `locked`.

## Decision

### Entity Groups

1. **Type Definitions** — Catalogue-level blueprints: caseType, statusType, resultType, roleType, propertyDefinition, documentType, decisionType
2. **Instance Entities** — Runtime case data: case, task, role, result, statusRecord, decision, document
3. **Link Entities** — Relation join tables: documentLink, caseDocument, caseObject, caseProperty, decisionDocument, zaaktypeInformatieobjecttype
4. **ZGW / Notification** — Catalogus, kanaal, abonnement, customerContact, dispatch, usageRights
5. **Voorstel / Parafering** — Internal approval workflow: voorstel, parafeerroute, parafeeractie
6. **Bezwaar / Beroep** — Objection lifecycle: objection, hearingSession, advisoryReport, appealDecision
7. **VTH / Enforcement** — Inspection and enforcement: adviesAanvraag, handhavingsactie, inspectieChecklist, inspectieRapport, mapLayer, workflowTemplate

---

## Group 1: Type Definitions

### caseType
**CMMN:** `CaseDefinition` / `CasePlanModel` template
**Schema.org:** `schema:Project`
**ZGW:** `ZaakType` (Catalogi API 1.3.x)
_Blueprint for a category of cases — controls lifecycle, deadlines, confidentiality, and archival._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string (max 255) | Yes | Name of this case type (translatable) |
| description | string | No | Detailed description (translatable) |
| identifier | string | No | Auto-generated identifier |
| catalogus | uuid | No | Reference to parent catalogus |
| purpose | string | No | Goal of this case type (translatable) |
| trigger | string | No | What triggers creation of a case (translatable) |
| subject | string | No | Subject matter (translatable) |
| processingDeadline | string (ISO 8601 duration) | No | Processing deadline (e.g. P30D) |
| confidentiality | enum | No | openbaar / beperkt_openbaar / intern / zaakvertrouwelijk / vertrouwelijk / confidentieel / geheim / zeer_geheim |
| isDraft | boolean (default: true) | No | Whether this case type is a draft |
| validFrom | date | No | Date from which this case type is valid |
| validUntil | date | No | Date until which it is valid (null = indefinite) |
| origin | string | No | Initiator action (e.g. indienen, aanvragen) |
| suspensionAllowed | boolean (default: false) | No | Whether cases can be suspended |
| extensionAllowed | boolean (default: false) | No | Whether deadline can be extended |
| extensionPeriod | string (ISO 8601 duration) | No | Extension period duration |
| publicationRequired | boolean (default: false) | No | Whether publication of decision is required |
| internalOrExternal | enum | No | intern / extern |
| handlerAction | string | No | Action performed by the handler |
| productsOrServices | string (JSON array) | No | URLs to products or services |
| selectionListProcessType | uri | No | URL to selectielijst process type |
| referenceProcess | string (JSON object) | No | Reference process definition |
| responsible | string | No | Responsible person or department |
| relatedCaseTypes | string (JSON array) | No | Related case types |
| subCaseTypes | array of string | No | References to sub-case types (deelzaaktypen) |
| decisionTypes | array of string | No | References to linked decision types (besluittypen) |

**Relations:**
- → statusType (one-to-many): lifecycle phases
- → resultType (one-to-many): possible outcomes
- → roleType (one-to-many): participant role definitions
- → propertyDefinition (one-to-many): custom field definitions
- → documentType (one-to-many): required documents
- → decisionType (many-to-many): linked decision types
- → workflowTemplate (one-to-many): workflow processes

---

### statusType
**Schema.org:** `schema:ActionStatusType`
**ZGW:** `StatusType`
_Lifecycle phase definition for a case type._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of this status (e.g. Ontvangen, In behandeling) (translatable) |
| description | string | No | Description of this status phase (translatable) |
| caseType | uuid | Yes | Reference to the parent case type |
| order | integer (default: 0) | Yes | Position in the lifecycle (lower = earlier) |
| isFinal | boolean (default: false) | No | Whether this is a terminal/final status |

**Relations:**
- → caseType (many-to-one)

---

### resultType
**Schema.org:** `schema:Thing`
**ZGW:** `ResultaatType`
_Case outcome type definition with archival retention rules._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of this result type (e.g. Vergunning verleend) (translatable) |
| description | string | No | Description/toelichting (translatable) |
| genericDescription | string | No | Generic description from selectielijst resultaattypeomschrijving |
| caseType | uuid | Yes | Reference to the parent case type |
| archivalPeriod | string (ISO 8601 duration) | No | Archival retention period |
| archivalAction | enum | No | bewaren / vernietigen / blijvend_bewaren |
| sourceDateArchiveProcedure | string (JSON object) | No | BrondatumArchiefprocedure config (afleidingswijze, procestermijn, datumkenmerk) |
| selectionListClass | uri | No | URL to selectielijstklasse |

**Relations:**
- → caseType (many-to-one)

---

### roleType
**Schema.org:** `schema:Role`
**ZGW:** `RolType`
_Participant role type definition for a case type._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of the role (e.g. Behandelaar, Adviseur) (translatable) |
| description | string | No | Description of this role type (translatable) |
| caseType | uuid | Yes | Reference to the parent case type |

**Relations:**
- → caseType (many-to-one)

---

### propertyDefinition
**Schema.org:** `schema:PropertyValueSpecification`
**ZGW:** `Eigenschap`
_Custom field definition for a case type._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of the custom property |
| definition | string | No | Short definition |
| description | string | No | Longer explanation |
| caseType | uuid | Yes | Reference to the parent case type |
| propertyType | enum | No | string / number / boolean / date / url / email |
| isRequired | boolean (default: false) | No | Whether required on cases |
| defaultValue | string | No | Default value |

**Relations:**
- → caseType (many-to-one)

---

### documentType
**Schema.org:** `schema:DigitalDocument`
**ZGW:** `InformatieObjectType`
_Document type requirement definition for a case type._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of the document type (translatable) |
| description | string | No | Description (translatable) |
| catalogus | uuid | No | Reference to parent catalogus |
| caseType | uuid | No | Reference to the parent case type |
| isDraft | boolean (default: true) | No | Whether this is a draft (concept) |
| confidentiality | enum | No | openbaar / beperkt_openbaar / intern / zaakvertrouwelijk / vertrouwelijk / confidentieel / geheim / zeer_geheim |
| category | string | No | Document type category |
| isRequired | boolean (default: false) | No | Whether this document is required |
| allowedMimeTypes | string (JSON array) | No | Allowed MIME types |
| validFrom | date | No | Date from which valid |
| validUntil | date | No | Date until which valid |

**Relations:**
- → caseType (many-to-one)

---

### decisionType
**Schema.org:** `schema:ChooseAction`
**ZGW:** `BesluitType`
_Decision type definition for a case type._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of this decision type (translatable) |
| description | string | No | Description (translatable) |
| catalogus | uuid | No | Reference to parent catalogus |
| caseType | uuid | No | Reference to the parent case type |
| isDraft | boolean (default: true) | No | Whether this is a draft |
| publicationRequired | boolean (default: false) | No | Whether publication is required |
| caseTypes | array of string | No | References to case types (zaaktype URLs) |
| documentTypes | array of string | No | References to document types (informatieobjecttype URLs) |
| validFrom | date | No | Date from which valid |
| validUntil | date | No | Date until which valid |

**Relations:**
- → caseType (many-to-one)

---

## Group 2: Instance Entities

### case
**CMMN:** `CaseInstance` (runtime)
**Schema.org:** `schema:Project`
**ZGW:** `Zaak`
_A case instance in the case management system._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string (max 255) | Yes | Title of this case |
| description | string | No | Detailed description |
| identifier | string | No | Auto-generated case identifier (e.g. 2026-0042) |
| caseType | uuid (facetable) | Yes | Reference to the case type |
| status | uuid (facetable) | No | Reference to the current status type |
| result | uuid | No | Reference to the result record (set on completion) |
| startDate | date | No | Date the case was started |
| endDate | date | No | Date the case was completed |
| plannedEndDate | date | No | Planned end date |
| deadline | date | No | Processing deadline |
| confidentiality | enum (facetable) | No | openbaar / beperkt_openbaar / intern / zaakvertrouwelijk / vertrouwelijk / confidentieel / geheim / zeer_geheim |
| assignee | string (facetable) | No | Nextcloud user ID of the primary handler |
| priority | enum (facetable, default: normal) | No | low / normal / high / urgent |
| parentCase | uuid | No | Reference to parent case (for sub-cases / deelzaken) |
| relatedCases | string (JSON array) | No | References to related cases |
| geometry | string (JSON object) | No | GeoJSON geometry for location-based cases |
| statusHistory | string (JSON array) | No | History of status changes |
| activity | string (JSON array) | No | Activity log entries |
| extensionCount | integer (default: 0) | No | Number of deadline extensions applied |
| sourceOrganisation | string (max 9) | No | RSIN of the creating organization |
| archiveNomination | enum | No | blijvend_bewaren / vernietigen |
| archiveActionDate | date | No | Date when archive action executes |
| archiveStatus | enum | No | nog_te_archiveren / gearchiveerd / gearchiveerd_procestermijn_onbekend / overgedragen |
| paymentIndication | enum | No | nvt / nog_niet / gedeeltelijk / geheel |
| lastPaymentDate | date | No | Date of last payment |
| communicationChannel | uri | No | URL reference to communication channel |
| workflowTemplate | uuid | No | Reference to the bound workflow template |
| workflowVersion | integer | No | Version number of the bound workflow template |

**Relations:**
- → caseType (many-to-one)
- → statusType (many-to-one, current status)
- → task (one-to-many, CASCADE delete)
- → role (one-to-many, CASCADE delete)
- → result (one-to-one, CASCADE delete)
- → statusRecord (one-to-many, CASCADE delete)
- → decision (one-to-many, CASCADE delete)
- → caseDocument (one-to-many, CASCADE delete)
- → caseObject (one-to-many, CASCADE delete)
- → caseProperty (one-to-many, CASCADE delete)
- → customerContact (one-to-many, CASCADE delete)
- → voorstel (one-to-many, CASCADE delete)

---

### task
**CMMN:** `HumanTask`
**Schema.org:** `schema:Action`
**ZGW:** `Taak`
_A task within a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string (max 255) | Yes | Title of this task |
| description | string | No | Detailed description |
| status | enum (facetable, default: available) | No | available / active / completed / terminated / disabled (CMMN HumanTask lifecycle) |
| case | uuid (CASCADE delete) | Yes | Reference to the parent case |
| assignee | string (facetable) | No | Nextcloud user ID of the assigned user |
| dueDate | date-time | No | Due date for this task |
| priority | enum (facetable, default: normal) | No | low / normal / high / urgent |
| completedDate | date-time | No | Date the task was completed |
| workflowStepId | string | No | UUID of the workflow step that generated this task |
| checklist | string (JSON array) | No | Checklist items ({id, label, checked}) |

**Relations:**
- → case (many-to-one, CASCADE delete)

---

### role
**Schema.org:** `schema:Role`
**ZGW:** `Rol`
_A role assignment on a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Display name for this role assignment |
| roleType | uuid | Yes | Reference to the role type |
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| participant | string | Yes | Nextcloud user ID or contact reference |
| description | string | No | Description of this role assignment |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → roleType (many-to-one)

---

### result
**Schema.org:** `schema:Thing`
**ZGW:** `Resultaat`
_A case outcome record._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | No | Name of this result |
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| resultType | uuid | Yes | Reference to the result type |
| description | string | No | Description of this result |

**Relations:**
- → case (one-to-one, CASCADE delete)
- → resultType (many-to-one)

---

### statusRecord
**Schema.org:** `schema:Event`
**ZGW:** `Status`
_A status transition record for a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| statusType | uuid | Yes | Reference to the status type |
| description | string | No | Status transition description |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → statusType (many-to-one)

---

### decision
**Schema.org:** `schema:ChooseAction`
**ZGW:** `Besluit`
_A formal decision on a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string (max 255) | No | Title of this decision |
| case | uuid (CASCADE delete) | No | Reference to the case |
| description | string | No | Description of this decision |
| decisionType | uuid | No | Reference to the decision type |
| responsibleOrganisation | string | No | RSIN of the responsible organisation |
| decisionDate | date | No | Date the decision was made |
| effectiveDate | date | No | Date the decision takes effect |
| expiryDate | date | No | Date the decision expires |
| publicationDate | date | No | Publication date |
| deliveryDate | date | No | Delivery date |
| explanation | string | No | Explanation of the decision |
| governingBody | string | No | Governing body (bestuursorgaan) that made the decision |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → decisionType (many-to-one)
- → decisionDocument (one-to-many, CASCADE delete)
- → objection (one-to-many, via contestedDecision)
- → appealDecision (one-to-many, via contestedDecision)

---

### document
**Schema.org:** `schema:DigitalDocument`
**ZGW:** `EnkelvoudigInformatieObject`
_A document (enkelvoudig informatieobject) in the document registry._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| identifier | string | No | Auto-generated document identifier |
| sourceOrganisation | string | No | RSIN of the source organisation |
| creationDate | date | No | Date the document was created |
| title | string (max 255) | Yes | Title of this document |
| confidentiality | enum | No | openbaar / beperkt_openbaar / intern / zaakvertrouwelijk / vertrouwelijk / confidentieel / geheim / zeer_geheim |
| author | string | No | Author of the document |
| status | enum | No | in_bewerking / ter_vaststelling / definitief / gearchiveerd |
| format | string | No | MIME type (e.g. application/pdf) |
| language | string (default: nld) | No | ISO 639-2/B language code |
| fileName | string | No | Original file name |
| fileSize | integer | No | File size in bytes |
| content | string | No | Base64-encoded file content or file reference |
| link | uri | No | URL to the document |
| description | string | No | Description of the document |
| documentType | uuid | No | Reference to the document type |
| locked | boolean (default: false) | No | Whether the document is locked for editing |
| lockId | string | No | Identifier of the current lock |
| fileParts | string (JSON array) | No | References to file parts for chunked uploads |
| usageRightsIndication | boolean (nullable) | No | Whether usage rights have been set |

**Relations:**
- → documentType (many-to-one)
- → caseDocument (one-to-many, via case linking)
- → decisionDocument (one-to-many, via decision linking)

---

## Group 3: Link Entities

### documentLink
**Schema.org:** `schema:DigitalDocument`
**ZGW:** `ObjectInformatieObject`
_A link between a document and a case or decision._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| document | uri | Yes | URI reference to the document (EnkelvoudigInformatieObject) |
| object | uri | Yes | URI reference to the related object (zaak or besluit) |
| objectType | enum | Yes | zaak / besluit |

---

### caseDocument
**ZGW:** `ZaakInformatieObject`
_Links a document to a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| document | uri | Yes | URI reference to the document |
| title | string | No | Title/description of the relation |
| description | string | No | Description of the relation |
| registrationDate | date | No | Registration date |

**Relations:**
- → case (many-to-one, CASCADE delete)

---

### caseObject
**ZGW:** `ZaakObject`
_Links an external object to a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| objectUrl | uri | No | URL of the external object |
| objectType | string | Yes | Type of the external object |
| objectIdentification | string | No | JSON identification of the object |
| description | string | No | Description of the relation |

**Relations:**
- → case (many-to-one, CASCADE delete)

---

### caseProperty
**ZGW:** `ZaakEigenschap`
_A custom property value on a specific case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| propertyDefinition | uuid | Yes | Reference to the property definition (eigenschap) |
| value | string | Yes | The property value |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → propertyDefinition (many-to-one)

---

### decisionDocument
**ZGW:** `BesluitInformatieObject`
_Links a document to a decision._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| decision | uuid (CASCADE delete) | Yes | Reference to the decision |
| document | uri | Yes | URI reference to the document |

**Relations:**
- → decision (many-to-one, CASCADE delete)

---

### zaaktypeInformatieobjecttype
**ZGW:** `ZaakTypeInformatieObjectType`
_Links a case type to a document type with direction and ordering._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| zaaktype | uuid | Yes | Reference to the case type |
| informatieobjecttype | uuid | Yes | Reference to the document type |
| volgnummer | integer | Yes | Ordering number |
| richting | enum | Yes | inkomend / intern / uitgaand |
| statustype | uuid | No | Reference to a status type |

---

## Group 4: ZGW / Notification Entities

### catalogus
**Schema.org:** `schema:DataCatalog`
**ZGW:** `Catalogus`
_Groups case types, decision types, and document types._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| domein | string (max 5) | Yes | Abbreviated domain name |
| rsin | string (max 9) | No | RSIN of the responsible organisation |
| contactpersoonBeheerNaam | string (max 40) | No | Name of the management contact |
| contactpersoonBeheerTelefoonnummer | string (max 20) | No | Phone number of the management contact |
| contactpersoonBeheerEmailadres | string (max 254) | No | Email of the management contact |

---

### kanaal
**Schema.org:** `schema:BroadcastChannel`
**ZGW:** `Kanaal`
_A notification channel for ZGW event distribution._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| naam | string (max 50) | Yes | Name of this channel (e.g. zaken, documenten) |
| documentatieLink | uri | No | URL to API documentation for this channel |
| filters | string (JSON array) | No | Available filter attributes for this channel |

---

### abonnement
**Schema.org:** `schema:SubscribeAction`
**ZGW:** `Abonnement`
_A subscription for receiving ZGW notifications._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| callbackUrl | uri | Yes | URL to POST notifications to |
| auth | string | Yes | Authorization header value for callback requests |
| kanalen | string (JSON array) | Yes | Channels and filters to subscribe to |

---

### customerContact
**ZGW:** `KlantContact`
_A customer contact moment for a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| contactDateTime | date-time | No | Date-time of the contact |
| channel | string | No | Communication channel |
| subject | string | No | Subject of the contact |
| initiator | string | No | Who initiated the contact |

**Relations:**
- → case (many-to-one, CASCADE delete)

---

### dispatch
**ZGW:** `Verzending`
_A document dispatch record (verzending)._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| document | uri | Yes | URI reference to the document |
| involvedParty | uri | No | URI of the involved party |
| relationshipType | string | Yes | Type of relationship (afzender/geadresseerde) |
| description | string | No | Description of the dispatch |
| receiveDate | date | No | Date received |
| sendDate | date | No | Date sent |
| contactPerson | uri | No | Contact person URI |
| contactPersonName | string | No | Name of the contact person |

---

### usageRights
**Schema.org:** `schema:DigitalDocument`
**ZGW:** `GebruiksRechten`
_Usage rights (gebruiksrechten) for a document._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| document | uri | Yes | URI reference to the document (EnkelvoudigInformatieObject) |
| startDate | string | Yes | Start date of the usage rights |
| endDate | string | No | End date of the usage rights |
| conditionsDescription | string | Yes | Description of the usage conditions |

---

## Group 5: Voorstel / Parafering

### voorstel
**Schema.org:** `schema:CreativeWork`
_A B&W voorstel (proposal) for decision-making in a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | Reference to the parent case |
| type | enum (facetable) | Yes | dt_advies / collegeadvies / raadsvoorstel |
| onderwerp | string (max 255) | Yes | Subject (usually derived from case title) |
| steller | string (facetable) | Yes | Nextcloud user UID who created the voorstel |
| afdeling | string | No | Department of the steller |
| portefeuillehouder | string | No | Nextcloud user UID of the portfolio holder (wethouder) |
| status | enum (facetable, default: concept) | Yes | concept / in_parafering / ter_accordering / geaccordeerd / aangeboden / besloten / gearchiveerd / teruggestuurd |
| parafeerroute | uuid | No | Reference to the parafeerroute being used |
| routeSnapshot | string (JSON array) | No | Snapshot of parafeerroute steps at submission time |
| currentStep | integer (default: 0) | No | Current step in the parafeerroute (1-based, 0 = not yet submitted) |
| returnedFromStep | integer | No | Step from which the voorstel was returned |
| document | string | No | Nextcloud file ID of the primary voorstel document |
| bijlagen | array of string | No | Nextcloud file IDs of attached documents |
| behandeling | enum | No | hamerstuk / bespreekstuk |
| decision | uuid | No | Reference to the linked decision (set when besluit is registered) |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → parafeerroute (many-to-one)
- → parafeeractie (one-to-many, CASCADE delete)

---

### parafeerroute
**Schema.org:** `schema:HowTo`
_A configurable endorsement route defining the sequence of parafering steps for a voorstel._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of this route (e.g. Collegeadvies - Omgevingsvergunning) |
| caseType | uuid (facetable) | No | Reference to the case type this route is associated with |
| voorstelType | enum | No | dt_advies / collegeadvies / raadsvoorstel |
| steps | array of objects | Yes | Ordered list of parafering steps ({order, type, actor, actorType, mandatory, label}) |
| isDefault | boolean (default: false) | No | Whether this is the default route for the linked case type and voorstel type |
| description | string | No | Description of when this route should be used |

**Step types:** advies / parafering / accordering
**Actor types:** user / group / role

---

### parafeeractie
**Schema.org:** `schema:Action`
_An immutable record of a parafering action on a voorstel step._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| voorstel | uuid (CASCADE delete) | Yes | Reference to the voorstel |
| step | integer | Yes | Step number in the parafeerroute |
| actor | string | Yes | Nextcloud user UID who performed the action |
| actorType | enum (default: user) | No | user / delegate |
| onBehalfOf | string | No | Nextcloud user UID of the principal (if acting as delegate) |
| action | enum (facetable) | Yes | parafered / returned / advised / skipped |
| comment | string | No | Comment or reason (mandatory for returned/skipped) |
| advice | string | No | Advisory text (for advies steps) |
| mandate | string | No | Mandate reference (for delegate actions) |

**Relations:**
- → voorstel (many-to-one, CASCADE delete)

---

## Group 6: Bezwaar / Beroep

### objection
**Schema.org:** `schema:Message`
**ZGW:** `Bezwaarschrift`
_Bezwaarschrift (objection letter) — formal objection content linked to a bezwaar case and the contested decision._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | The bezwaar case this objection belongs to |
| contestedDecision | uuid | Yes | The original besluit being contested |
| grounds | string | Yes | Grounds for objection (gronden van bezwaar) |
| requestedRelief | string | No | What outcome the bezwaarmaker seeks |
| receivedDate | date | Yes | Date the bezwaarschrift was received |
| receivedChannel | enum | Yes | brief / email / formulier / balie |
| isTimely | boolean | No | Whether objection was filed within the 6-week term (Awb art. 6:7) |
| timelinessAssessment | string | No | Explanation of timeliness determination |
| proVoorziening | boolean (default: false) | No | Whether voorlopige voorziening (interim relief) was requested |
| attachments | string (JSON array) | No | Document references uploaded by bezwaarmaker |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → decision (many-to-one, via contestedDecision)

---

### hearingSession
**Schema.org:** `schema:Event`
**ZGW:** `Hoorzitting`
_Manages scheduling, invitations, and minutes for bezwaar hearings per Awb art. 7:2._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | The bezwaar case this hearing belongs to |
| scheduledDate | date-time | Yes | Date and time of the hearing |
| location | string | No | Physical location or 'Online' for video hearings |
| videoCallUrl | uri | No | Video conference link for online hearings |
| chairperson | uuid (→ role) | Yes | Who chairs the hearing (voorzitter) |
| members | string (JSON array) | No | Committee member role UUIDs |
| invitees | string (JSON array) | Yes | Invitee objects (name, role, email, status) |
| minutesSummary | string | No | Summary of what was discussed (verslag) |
| minutesDocument | uuid | No | Reference to full hearing minutes document |
| status | enum (default: gepland) | Yes | gepland / uitgenodigd / uitgevoerd / geannuleerd / afgezien |
| hearingWaived | boolean (default: false) | No | Bezwaarmaker has waived the right to be heard |
| waiverReason | string | No | Reason for waiving hearing right |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → role (many-to-one, via chairperson)

---

### advisoryReport
**Schema.org:** `schema:Report`
**ZGW:** `AdviesBezwaarschriftencommissie`
_Advisory committee report per Awb art. 7:13._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | The bezwaar case this report belongs to |
| hearingSession | uuid (→ hearingSession) | No | The hearing session this report is based on |
| committeeChair | uuid (→ role) | Yes | Voorzitter who signed the report |
| committeeMembers | string (JSON array) | No | Committee member role UUIDs |
| adviceDate | date | Yes | Date the advice was issued |
| adviceType | enum | Yes | gegrond / ongegrond / deels_gegrond / niet_ontvankelijk |
| summary | string | Yes | Summary of the committee's advice |
| grounds | string | Yes | Legal reasoning and grounds for the advice |
| recommendation | string | Yes | Recommended action for the bestuursorgaan |
| deviationFromPrimaryDecision | boolean | Yes | Whether committee advises differently from original decision |
| reportDocument | uuid | No | Reference to full advisory report document |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → hearingSession (many-to-one)
- → role (many-to-one, via committeeChair)

---

### appealDecision
**Schema.org:** `schema:LegalForceStatus`
**ZGW:** `BeslissingOpBezwaar`
_Beslissing op bezwaar — formal decision recording with disposition and rechtsmiddelenclausule per Awb art. 7:11-7:12._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | The bezwaar case |
| contestedDecision | uuid (→ decision) | Yes | The original besluit being contested |
| advisoryReport | uuid (→ advisoryReport) | No | The committee's advisory report |
| dispositionType | enum | Yes | gegrond / ongegrond / deels_gegrond / niet_ontvankelijk |
| dispositionDetails | string | Yes | Detailed motivation (motiveringsplicht art. 7:12) |
| followsAdvice | boolean | No | Whether the decision follows the committee's advice |
| deviationReason | string | No | Reason for deviating from committee advice |
| remedialAction | string | No | Corrective action if gegrond/deels_gegrond |
| replacementDecision | uuid (→ decision) | No | New besluit that replaces the contested one |
| decisionDate | date | Yes | Date the decision was made |
| effectiveDate | date | Yes | Date the decision takes legal effect |
| appealInformation | string | Yes | Beroep possibilities (rechtsmiddelenclausule) |
| decisionMaker | uuid (→ role) | Yes | The person/body that made the decision |
| decisionDocument | uuid | No | Reference to the formal decision letter document |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → decision (many-to-one, via contestedDecision)
- → advisoryReport (many-to-one)
- → role (many-to-one, via decisionMaker)

---

## Group 7: VTH / Enforcement

### workflowTemplate
**Schema.org:** `schema:HowTo`
**CMMN:** `CasePlanModel`
_A workflow definition for a case type — defines process steps, status transitions, guards, and automatic actions._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string (max 255) | Yes | Name of this workflow template |
| description | string | No | Purpose and usage notes |
| caseType | uuid (CASCADE delete) | Yes | Reference to the case type |
| version | integer (default: 1) | No | Auto-incrementing version number |
| isActive | boolean (default: false) | No | Whether this is the active version for new cases |
| isDraft | boolean (default: true) | No | Draft templates cannot be used for new cases |
| steps | string (JSON array) | No | WorkflowStep objects: {id, title, description, status (uuid), order, assigneeRole (uuid), isRequired, checklist, automaticActions} |
| transitions | string (JSON array) | No | StatusTransition objects: {id, fromStatus, toStatus, label, guards, automaticActions, allowedRoles}. Guard types: checklist, requiredField, requiredDocument, roleGuard. Action types: sendEmail, createTask, createSubCase, webhook, setField, notify |
| nodePositions | string (JSON map) | No | Map of status UUID to {x, y} canvas positions for visual editor |
| parentWorkflow | uuid | No | Reference to parent workflow template for inheritance (Enterprise tier) |

**Relations:**
- → caseType (many-to-one, CASCADE delete)

---

### adviesAanvraag
**Schema.org:** `schema:AskAction`
_A request for internal or external advice on a case, with deadline tracking._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid | Yes | Reference to the case this advice is requested for |
| adviseur | string | Yes | User UID (internal) or organization name (external) |
| type | enum | Yes | intern / extern |
| onderwerp | string | No | Subject/topic of the advice request |
| deadline | date | No | Deadline for receiving the advice |
| status | enum (default: aangevraagd) | No | aangevraagd / ontvangen / verlopen |
| adviesDocument | string | No | Nextcloud file ID of the advice document |
| requestedAt | date-time | No | Timestamp when the advice was requested |
| receivedAt | date-time | No | Timestamp when the advice was received |
| questions | string | No | Specific questions for the adviseur |

**Relations:**
- → case (many-to-one)

---

### handhavingsactie
**Schema.org:** `schema:LegalForceStatus`
_An enforcement action (handhavingsactie) classified per Landelijke Handhavingsstrategie (LHS)._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid | Yes | Reference to the handhavingszaak |
| type | enum | Yes | waarschuwing / vooraankondiging / last_onder_dwangsom / bestuursdwang / proces_verbaal |
| ernst | enum | Yes | gering / aanzienlijk / ernstig (LHS ernst axis) |
| gedrag | enum | Yes | goedwillend / onverschillig / calculerend / crimineel (LHS gedrag axis) |
| interventie | string | No | Suggested intervention from LHS matrix |
| begunstigingstermijn | integer | No | Grace period in days before enforcement takes effect |
| dwangsomBedrag | number | No | Penalty amount per violation (EUR) |
| dwangsomMaximaal | number | No | Maximum total penalty amount (EUR) |
| effectueringsDatum | date | No | Date when enforcement action takes effect |
| status | enum (default: opgelegd) | No | opgelegd / verbeurd / geeffectueerd / ingetrokken |
| overrideReason | string | No | Documented reasoning if LHS suggestion was overridden |

**Relations:**
- → case (many-to-one)

---

### inspectieChecklist
**Schema.org:** `schema:HowTo`
_Configurable inspection checklist template linked to a case type, with versioning support._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of this checklist (e.g. 'Bouwtoezicht fase 1 - Fundering') |
| caseType | uuid | Yes | Reference to the case type |
| version | integer (default: 1) | No | Version number (incremented on edit) |
| status | enum (default: draft) | No | draft / active / archived |
| items | array of objects | No | Ordered checklist items ({order, label, type, required, fotoRequired, options, helpText}) |

**Item types:** ja_nee_nvt / tekst / getal / foto / meerkeuze

**Relations:**
- → caseType (many-to-one)

---

### inspectieRapport
**Schema.org:** `schema:Report`
_A completed inspection report generated from a checklist, stored on the case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid | Yes | Reference to the case (toezichtzaak) |
| checklist | uuid | Yes | Reference to the inspectieChecklist used |
| inspector | string | Yes | User UID of the inspector |
| inspectionDate | date-time | Yes | Date and time of the inspection |
| location | string | No | GPS coordinates or address of the inspection location |
| result | enum | No | conform / niet_conform / deels_conform (auto-calculated from items) |
| failedItems | integer (default: 0) | No | Count of failed checklist items |
| items | array of objects | No | Completed checklist item results ({itemId, result, comment, measurement, photos}) |
| photos | array of string | No | All Nextcloud file IDs of photos taken during inspection |
| remarks | string | No | General remarks about the inspection |
| followUpRequired | boolean (default: false) | No | Whether follow-up action is required |

**Relations:**
- → case (many-to-one)
- → inspectieChecklist (many-to-one)

---

### mapLayer
_GIS map layer configuration for case maps._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string (max 255) | Yes | Display name for the layer in the layer switcher |
| layerType | enum | Yes | tile / wms / wfs / geojson |
| url | uri | Yes | Service URL (tile template, WMS base URL, WFS endpoint, or GeoJSON URL) |
| layers | string | No | WMS/WFS layer name(s), comma-separated |
| format | string (default: image/png) | No | Image format for WMS |
| attribution | string | No | Attribution text for the layer |
| isDefault | boolean (default: false) | No | Whether to show this layer on initial load |
| isBaseLayer | boolean (default: false) | No | If true, only one base layer visible at a time |
| opacity | number 0.0–1.0 (default: 1.0) | No | Layer opacity |
| minZoom | integer | No | Minimum zoom level for visibility |
| maxZoom | integer | No | Maximum zoom level for visibility |
| order | integer (default: 0) | No | Display order in the layer switcher |
| style | string (JSON object) | No | Style for GeoJSON/WFS features (color, weight, fillColor, fillOpacity) |
| proxyEnabled | boolean (default: false) | No | Whether to route requests through the backend GIS proxy |

---

## Entity Count Summary

| Group | Count | Schemas |
|-------|-------|---------|
| Type Definitions | 7 | caseType, statusType, resultType, roleType, propertyDefinition, documentType, decisionType |
| Instance Entities | 7 | case, task, role, result, statusRecord, decision, document |
| Link Entities | 6 | documentLink, caseDocument, caseObject, caseProperty, decisionDocument, zaaktypeInformatieobjecttype |
| ZGW / Notification | 6 | catalogus, kanaal, abonnement, customerContact, dispatch, usageRights |
| Voorstel / Parafering | 3 | voorstel, parafeerroute, parafeeractie |
| Bezwaar / Beroep | 4 | objection, hearingSession, advisoryReport, appealDecision |
| VTH / Enforcement | 6 | workflowTemplate, adviesAanvraag, handhavingsactie, inspectieChecklist, inspectieRapport, mapLayer |
| **Total** | **39** | |

## ZGW Coverage

| ZGW Entity | Procest Entity | Notes |
|------------|----------------|-------|
| ZaakType | caseType | + draft lifecycle, CMMN alignment |
| StatusType | statusType | Direct mapping |
| ResultaatType | resultType | + archival rules |
| RolType | roleType | Direct mapping |
| Eigenschap | propertyDefinition | Direct mapping |
| InformatieObjectType | documentType | + isRequired, allowedMimeTypes |
| BesluitType | decisionType | Direct mapping |
| Zaak | case | + priority, workflowTemplate, CMMN status |
| Status | statusRecord | Direct mapping |
| Resultaat | result | Direct mapping |
| Rol | role | Direct mapping |
| Besluit | decision | Direct mapping |
| EnkelvoudigInformatieObject | document | + locked, fileParts, usageRightsIndication |
| ObjectInformatieObject | documentLink | Direct mapping |
| ZaakInformatieObject | caseDocument | Direct mapping |
| ZaakObject | caseObject | Direct mapping |
| ZaakEigenschap | caseProperty | Direct mapping |
| BesluitInformatieObject | decisionDocument | Direct mapping |
| Catalogus | catalogus | Direct mapping |
| Kanaal | kanaal | Direct mapping |
| Abonnement | abonnement | Direct mapping |
| KlantContact | customerContact | Direct mapping |
| Verzending | dispatch | Direct mapping |
| GebruiksRechten | usageRights | Direct mapping |
| ZaakTypeInformatieObjectType | zaaktypeInformatieobjecttype | Direct mapping |
| Bezwaarschrift | objection | + timeliness assessment |
| Hoorzitting | hearingSession | + video hearing support |
| AdviesBezwaarschriftencommissie | advisoryReport | Direct mapping |
| BeslissingOpBezwaar | appealDecision | + replacement decision link |

## Consequences

- All Procest data is stored in OpenRegister — Procest owns no database tables
- Schema changes require updating `lib/Settings/procest_register.json` and running the repair/import step
- The thin-client architecture means all CRUD operations go through the OpenRegister API from the Vue frontend
- ZGW compatibility is achieved through field-level mapping in API controllers, not by structuring storage around ZGW fields
- CMMN task lifecycle states (available, active, completed, terminated, disabled) are used for tasks to maintain standards alignment
- The parafering workflow is internal to Procest and has no direct ZGW equivalent
- Bezwaar/beroep entities implement the Awb (Algemene wet bestuursrecht) legal framework — timeliness check references art. 6:7, hearing per art. 7:2, advisory report per art. 7:13, appeal decision per art. 7:11-7:12
- VTH enforcement uses the Landelijke Handhavingsstrategie (LHS) matrix (ernst × gedrag axes)
- mapLayer is a configuration-only entity — it stores GIS layer definitions, not case data
