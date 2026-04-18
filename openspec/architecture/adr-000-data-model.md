# Data Model — Procest

**App:** Procest — Case management, VTH, forms
**Platform:** OpenRegister (register/schema/object pattern)
**Entities:** 39

OpenRegister built-in fields available on ALL entities (do NOT redefine):
id, uuid, uri, version, createdAt, updatedAt, owner, organization,
register, schema, relations, files, auditTrail, notes, tasks, tags, status, locked.

OpenRegister built-in capabilities (do NOT rebuild):
CRUD REST API, CSV/JSON/XML import+export, full-text search, filtering,
pagination, audit trails, file attachments, relation management, locking.

---

## abonnement
**Schema.org type:** `schema:SubscribeAction`
**Purpose:** A subscription (abonnement) for receiving ZGW notifications
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| callbackUrl | string | Yes | URL to POST notifications to |
| auth | string | Yes | Authorization header value for callback requests |
| kanalen | string | Yes | Channels and filters to subscribe to (JSON-encoded array) |

---

## adviesAanvraag
**Schema.org type:** `schema:AskAction`
**Purpose:** A request for internal or external advice on a case, with deadline tracking
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case this advice is requested for |
| adviseur | string | Yes | User UID (internal) or organization name (external) |
| type | string | Yes | Whether advice is from internal staff or external party |
| onderwerp | string | No | Subject/topic of the advice request |
| deadline | string | No | Deadline for receiving the advice |
| status | string | No | Current status of the advice request |
| adviesDocument | string | No | Nextcloud file ID of the advice document |
| requestedAt | string | No | Timestamp when the advice was requested |
| receivedAt | string | No | Timestamp when the advice was received |
| questions | string | No | Specific questions for the adviseur |

---

## advisoryReport
**Schema.org type:** `schema:Report`
**Purpose:** Advisory committee report (advies bezwaarschriftencommissie) — records the committee's advice on a bezwaar case per Awb art. 7:13
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | The bezwaar case this report belongs to |
| hearingSession | string | No | The hearing session this report is based on |
| committeeChair | string | Yes | Voorzitter who signed the report |
| committeeMembers | string | No | JSON-encoded array of committee member role UUIDs |
| adviceDate | string | Yes | Date the advice was issued |
| adviceType | string | Yes | Type of advice: upheld, rejected, partially upheld, inadmissible |
| summary | string | Yes | Summary of the committee's advice |
| grounds | string | Yes | Legal reasoning and grounds for the advice |
| recommendation | string | Yes | Recommended action for the bestuursorgaan |
| deviationFromPrimaryDecision | boolean | Yes | Whether the committee advises differently from the original decision |
| reportDocument | string | No | Reference to full advisory report document |

**Relations:**
- → case (many-to-one)
- → hearingSession (many-to-one)
- → role (many-to-one)

---

## appealDecision
**Schema.org type:** `schema:LegalForceStatus`
**Purpose:** Beslissing op bezwaar (decision on objection) — formal decision recording with disposition, motivation, and rechtsmiddelenclausule per Awb art. 7:11-7:12
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | The bezwaar case |
| contestedDecision | string | Yes | The original besluit being contested |
| advisoryReport | string | No | The committee's advisory report |
| dispositionType | string | Yes | Decision outcome type |
| dispositionDetails | string | Yes | Detailed motivation for the decision (motiveringsplicht art. 7:12) |
| followsAdvice | boolean | No | Whether the decision follows the committee's advice |
| deviationReason | string | No | Reason for deviating from committee advice (required when followsAdvice is false) |
| remedialAction | string | No | Corrective action taken if gegrond/deels_gegrond |
| replacementDecision | string | No | New besluit that replaces the contested one |
| decisionDate | string | Yes | Date the decision was made |
| effectiveDate | string | Yes | Date the decision takes legal effect |
| appealInformation | string | Yes | Information about beroep possibilities (rechtsmiddelenclausule) |
| decisionMaker | string | Yes | The person/body that made the decision |
| decisionDocument | string | No | Reference to the formal decision letter document |

**Relations:**
- → case (many-to-one)
- → decision (many-to-one)
- → advisoryReport (many-to-one)
- → decision (many-to-one)
- → role (many-to-one)

---

## case
**Schema.org type:** `schema:Project`
**Purpose:** A case instance in the case management system
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Title of this case |
| description | string | No | Detailed description of this case |
| identifier | string | No | Auto-generated case identifier (e.g. 2026-0042) |
| caseType | string | Yes | Reference to the case type |
| status | string | No | Reference to the current status type |
| result | string | No | Reference to the result record (set on completion) |
| startDate | string | No | Date the case was started |
| endDate | string | No | Date the case was completed |
| plannedEndDate | string | No | Planned end date |
| deadline | string | No | Processing deadline |
| confidentiality | string | No | Confidentiality level |
| assignee | string | No | Nextcloud user ID of the primary handler |
| priority | string | No | Case priority |
| parentCase | string | No | Reference to parent case (for sub-cases) |
| relatedCases | string | No | References to related cases (JSON-encoded array) |
| geometry | string | No | GeoJSON geometry for location-based cases (JSON-encoded object) |
| statusHistory | string | No | History of status changes (JSON-encoded array) |
| activity | string | No | Activity log entries (JSON-encoded array) |
| extensionCount | integer | No | Number of deadline extensions applied |
| sourceOrganisation | string | No | RSIN of the organization that created this case |
| archiveNomination | string | No | Whether the case should be permanently archived or destroyed |
| archiveActionDate | string | No | Date when the archive action should be executed |
| archiveStatus | string | No | Current archive status of the case |
| paymentIndication | string | No | Payment status indicator |
| lastPaymentDate | string | No | Date of the last payment |
| communicationChannel | string | No | URL reference to the communication channel |
| workflowTemplate | string | No | Reference to the bound workflow template |
| workflowVersion | integer | No | Version number of the bound workflow template |

---

## caseDocument
**Purpose:** Links a document to a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case |
| document | string | Yes | URI reference to the document |
| title | string | No | Title/description of the relation |
| description | string | No | Description of the relation |
| registrationDate | string | No | Registration date |

**Relations:**
- → case (many-to-one)

---

## caseObject
**Purpose:** Links an external object to a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case |
| objectUrl | string | No | URL of the external object |
| objectType | string | Yes | Type of the external object |
| objectIdentification | string | No | JSON identification of the object |
| description | string | No | Description of the relation |

**Relations:**
- → case (many-to-one)

---

## caseProperty
**Purpose:** A property value on a specific case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case |
| propertyDefinition | string | Yes | Reference to the property definition (eigenschap) |
| value | string | Yes | The property value |

**Relations:**
- → case (many-to-one)

---

## caseType
**Schema.org type:** `schema:Project`
**Purpose:** Case type definition — defines the blueprint for a category of cases including lifecycle, deadlines, and classification
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Name of this case type |
| description | string | No | Detailed description of this case type |
| identifier | string | No | Auto-generated identifier |
| catalogus | string | No | Reference to the parent catalogus |
| purpose | string | No | The purpose or goal of this case type |
| trigger | string | No | What triggers the creation of a case of this type |
| subject | string | No | The subject matter of this case type |
| processingDeadline | string | No | ISO 8601 duration for the processing deadline (e.g. P30D) |
| confidentiality | string | No | Confidentiality level |
| isDraft | boolean | No | Whether this case type is a draft (not yet published) |
| validFrom | string | No | Date from which this case type is valid |
| validUntil | string | No | Date until which this case type is valid (null = indefinite) |
| origin | string | No | Initiator action (e.g. indienen, aanvragen) |
| suspensionAllowed | boolean | No | Whether cases of this type can be suspended |
| extensionAllowed | boolean | No | Whether the processing deadline can be extended |
| extensionPeriod | string | No | ISO 8601 duration for extension period (e.g. P14D) |
| publicationRequired | boolean | No | Whether publication of the decision is required |
| internalOrExternal | string | No | Whether the case type is internal or external |
| handlerAction | string | No | Action performed by the handler |
| productsOrServices | string | No | URLs to products or services (JSON-encoded array) |
| selectionListProcessType | string | No | URL to the selection list process type |
| referenceProcess | string | No | Reference process definition (JSON-encoded object) |
| responsible | string | No | Responsible person or department |
| relatedCaseTypes | string | No | Related case types (JSON-encoded array) |
| subCaseTypes | array | No | References to sub-case types (deelzaaktypen) |
| decisionTypes | array | No | References to decision types (besluittypen) linked to this case type |

---

## catalogus
**Schema.org type:** `schema:DataCatalog`
**Purpose:** A catalogus groups case types, decision types, and document types
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| domein | string | Yes | Abbreviated domain name (max 5 characters) |
| rsin | string | No | RSIN of the responsible organisation |
| contactpersoonBeheerNaam | string | No | Name of the management contact |
| contactpersoonBeheerTelefoonnummer | string | No | Phone number of the management contact |
| contactpersoonBeheerEmailadres | string | No | Email of the management contact |

---

## customerContact
**Purpose:** A customer contact moment for a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case |
| contactDateTime | string | No | Date-time of the contact |
| channel | string | No | Communication channel |
| subject | string | No | Subject of the contact |
| initiator | string | No | Who initiated the contact |

**Relations:**
- → case (many-to-one)

---

## decision
**Schema.org type:** `schema:ChooseAction`
**Purpose:** A formal decision on a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | No | Title of this decision |
| case | string | No | Reference to the case |
| description | string | No | Description of this decision |
| decisionType | string | No | Reference to the decision type |
| responsibleOrganisation | string | No | RSIN of the responsible organisation |
| decisionDate | string | No | Date the decision was made |
| effectiveDate | string | No | Date the decision takes effect |
| expiryDate | string | No | Date the decision expires |
| publicationDate | string | No | Publication date |
| deliveryDate | string | No | Delivery date |
| explanation | string | No | Explanation of the decision |
| governingBody | string | No | The governing body that made the decision (bestuursorgaan) |

**Relations:**
- → case (many-to-one)

---

## decisionDocument
**Purpose:** Links a document to a decision
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| decision | string | Yes | Reference to the decision |
| document | string | Yes | URI reference to the document |

**Relations:**
- → decision (many-to-one)

---

## decisionType
**Schema.org type:** `schema:ChooseAction`
**Purpose:** Decision type definition for a case type
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this decision type |
| description | string | No | Description of this decision type |
| catalogus | string | No | Reference to the parent catalogus |
| caseType | string | No | Reference to the parent case type |
| isDraft | boolean | No | Whether this decision type is a draft (concept) |
| publicationRequired | boolean | No | Whether this decision type requires publication |
| caseTypes | array | No | References to case types (array of zaaktype URLs) |
| documentTypes | array | No | References to document types (array of informatieobjecttype URLs) |
| validFrom | string | No | Date from which this decision type is valid |
| validUntil | string | No | Date until which this decision type is valid |

---

## dispatch
**Purpose:** A document dispatch record
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| document | string | Yes | URI reference to the document |
| involvedParty | string | No | URI of the involved party |
| relationshipType | string | Yes | Type of relationship (afzender/geadresseerde) |
| description | string | No | Description of the dispatch |
| receiveDate | string | No | Date received |
| sendDate | string | No | Date sent |
| contactPerson | string | No | Contact person URI |
| contactPersonName | string | No | Name of the contact person |

---

## document
**Schema.org type:** `schema:DigitalDocument`
**Purpose:** A document (enkelvoudig informatieobject) in the document registry
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| identifier | string | No | Auto-generated document identifier |
| sourceOrganisation | string | No | RSIN of the source organisation |
| creationDate | string | No | Date the document was created |
| title | string | Yes | Title of this document |
| confidentiality | string | No | Confidentiality level |
| author | string | No | Author of the document |
| status | string | No | Document status |
| format | string | No | MIME type of the document (e.g. application/pdf) |
| language | string | No | Language of the document (ISO 639-2/B) |
| fileName | string | No | Original file name |
| fileSize | integer | No | File size in bytes |
| content | string | No | Base64-encoded file content or file reference |
| link | string | No | URL to the document |
| description | string | No | Description of the document |
| documentType | string | No | Reference to the document type |
| locked | boolean | No | Whether the document is locked for editing |
| lockId | string | No | Identifier of the current lock |
| fileParts | string | No | References to file parts for chunked uploads (JSON-encoded array) |
| usageRightsIndication | boolean | No | Indicates whether usage rights have been set for this document |

---

## documentLink
**Schema.org type:** `schema:DigitalDocument`
**Purpose:** A link between a document and a case or decision
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| document | string | Yes | URI reference to the document (EnkelvoudigInformatieObject) |
| object | string | Yes | URI reference to the related object (zaak or besluit) |
| objectType | string | Yes | Type of the related object |

---

## documentType
**Schema.org type:** `schema:DigitalDocument`
**Purpose:** Document type requirement for a case type
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this document type (e.g. Situatietekening) |
| description | string | No | Description of this document type |
| catalogus | string | No | Reference to the parent catalogus |
| caseType | string | No | Reference to the parent case type |
| isDraft | boolean | No | Whether this document type is a draft (concept) |
| confidentiality | string | No | Confidentiality level |
| category | string | No | Document type category |
| isRequired | boolean | No | Whether this document is required for the case |
| allowedMimeTypes | string | No | Allowed MIME types (JSON-encoded array) |
| validFrom | string | No | Date from which this document type is valid |
| validUntil | string | No | Date until which this document type is valid |

---

## handhavingsactie
**Schema.org type:** `schema:LegalForceStatus`
**Purpose:** An enforcement action (handhavingsactie) on a case, classified per the Landelijke Handhavingsstrategie (LHS)
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the handhavingszaak |
| type | string | Yes | Type of enforcement action |
| ernst | string | Yes | Severity of the violation (LHS ernst axis) |
| gedrag | string | Yes | Behavior of the violator (LHS gedrag axis) |
| interventie | string | No | Suggested intervention from LHS matrix (may be overridden) |
| begunstigingstermijn | integer | No | Grace period in days before enforcement takes effect |
| dwangsomBedrag | number | No | Penalty amount per violation (EUR) |
| dwangsomMaximaal | number | No | Maximum total penalty amount (EUR) |
| effectueringsDatum | string | No | Date when enforcement action takes effect |
| status | string | No | Current status of the enforcement action |
| overrideReason | string | No | Documented reasoning if the LHS suggestion was overridden |

---

## hearingSession
**Schema.org type:** `schema:Event`
**Purpose:** Hoorzitting (hearing) — manages scheduling, invitations, and minutes for bezwaar hearings per Awb art. 7:2
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | The bezwaar case this hearing belongs to |
| scheduledDate | string | Yes | Date and time of the hearing |
| location | string | No | Physical location or 'Online' for video hearings |
| videoCallUrl | string | No | Video conference link for online hearings |
| chairperson | string | Yes | Who chairs the hearing (voorzitter) |
| members | string | No | JSON-encoded array of committee member role UUIDs |
| invitees | string | Yes | JSON-encoded array of invitee objects (name, role, email, status) |
| minutesSummary | string | No | Summary of what was discussed (verslag) |
| minutesDocument | string | No | Reference to full hearing minutes document |
| status | string | Yes | Hearing session status |
| hearingWaived | boolean | No | Bezwaarmaker has waived the right to be heard |
| waiverReason | string | No | Reason for waiving hearing right |

**Relations:**
- → case (many-to-one)
- → role (many-to-one)

---

## inspectieChecklist
**Schema.org type:** `schema:HowTo`
**Purpose:** Configurable inspection checklist template linked to a case type, with versioning support
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this checklist (e.g. 'Bouwtoezicht fase 1 - Fundering') |
| caseType | string | Yes | Reference to the case type this checklist belongs to |
| version | integer | No | Version number of this checklist (incremented on edit) |
| status | string | No | Lifecycle status of this checklist version |
| items | array | No | Ordered list of checklist items |

---

## inspectieRapport
**Schema.org type:** `schema:Report`
**Purpose:** A completed inspection report generated from a checklist, stored on the case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case (toezichtzaak) this report belongs to |
| checklist | string | Yes | Reference to the inspectieChecklist used |
| inspector | string | Yes | User UID of the inspector |
| inspectionDate | string | Yes | Date and time of the inspection |
| location | string | No | GPS coordinates or address of the inspection location |
| result | string | No | Overall inspection result (auto-calculated from items) |
| failedItems | integer | No | Count of failed checklist items |
| items | array | No | Completed checklist item results |
| photos | array | No | All Nextcloud file IDs of photos taken during inspection |
| remarks | string | No | General remarks about the inspection |
| followUpRequired | boolean | No | Whether follow-up action is required |

---

## kanaal
**Schema.org type:** `schema:BroadcastChannel`
**Purpose:** A notification channel (kanaal) for ZGW event distribution
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| naam | string | Yes | Name of this channel (e.g. zaken, documenten) |
| documentatieLink | string | No | URL to API documentation for this channel |
| filters | string | No | Available filter attributes for this channel (JSON-encoded array) |

---

## mapLayer
**Purpose:** GIS map layer configuration for case maps — defines tile, WMS, WFS, or GeoJSON layers that can be displayed on case map views
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Display name for the layer in the layer switcher |
| layerType | string | Yes | The type of map layer (tile, wms, wfs, or geojson) |
| url | string | Yes | Service URL (tile template, WMS base URL, WFS endpoint, or GeoJSON URL) |
| layers | string | No | WMS/WFS layer name(s), comma-separated |
| format | string | No | Image format for WMS (e.g., image/png) |
| attribution | string | No | Attribution text for the layer |
| isDefault | boolean | No | Whether to show this layer on initial load |
| isBaseLayer | boolean | No | If true, only one base layer visible at a time |
| opacity | number | No | Layer opacity from 0.0 (transparent) to 1.0 (opaque) |
| minZoom | integer | No | Minimum zoom level for visibility |
| maxZoom | integer | No | Maximum zoom level for visibility |
| order | integer | No | Display order in the layer switcher |
| style | string | No | JSON-encoded style object for GeoJSON/WFS features (color, weight, fillColor, fillOpacity) |
| proxyEnabled | boolean | No | Whether to route requests through the backend GIS proxy (for CORS-restricted services) |

---

## objection
**Schema.org type:** `schema:Message`
**Purpose:** Bezwaarschrift (objection letter) — captures the formal objection content linked to a bezwaar case and the contested decision
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | The bezwaar case this objection belongs to |
| contestedDecision | string | Yes | The original besluit being contested |
| grounds | string | Yes | The grounds for objection (gronden van bezwaar) |
| requestedRelief | string | No | What outcome the bezwaarmaker seeks |
| receivedDate | string | Yes | Date the bezwaarschrift was received |
| receivedChannel | string | Yes | How the bezwaarschrift was received |
| isTimely | boolean | No | Whether the objection was filed within the 6-week term (Awb art. 6:7) |
| timelinessAssessment | string | No | Explanation of timeliness determination |
| proVoorziening | boolean | No | Whether a voorlopige voorziening (interim relief) was requested |
| attachments | string | No | JSON-encoded array of document references uploaded by bezwaarmaker |

**Relations:**
- → case (many-to-one)
- → decision (many-to-one)

---

## parafeeractie
**Schema.org type:** `schema:Action`
**Purpose:** An immutable record of a parafering action on a voorstel step
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| voorstel | string | Yes | Reference to the voorstel |
| step | integer | Yes | Step number in the parafeerroute |
| actor | string | Yes | Nextcloud user UID who performed the action |
| actorType | string | No | Whether the actor acted directly or as delegate |
| onBehalfOf | string | No | Nextcloud user UID of the principal (if acting as delegate) |
| action | string | Yes | The action performed |
| comment | string | No | Comment or reason (mandatory for returned/skipped) |
| advice | string | No | Advisory text (for advies steps) |
| mandate | string | No | Mandate reference (for delegate actions) |

**Relations:**
- → voorstel (many-to-one)

---

## parafeerroute
**Schema.org type:** `schema:HowTo`
**Purpose:** A configurable endorsement route defining the sequence of parafering steps for a voorstel
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this parafeerroute (e.g. Collegeadvies - Omgevingsvergunning) |
| caseType | string | No | Reference to the case type this route is associated with |
| voorstelType | string | No | Voorstel type this route applies to |
| steps | array | Yes | Ordered list of parafering steps |
| isDefault | boolean | No | Whether this is the default route for the linked case type and voorstel type |
| description | string | No | Description of when this route should be used |

---

## propertyDefinition
**Schema.org type:** `schema:PropertyValueSpecification`
**Purpose:** Custom field definition for a case type
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this custom property |
| definition | string | No | Short definition of this property |
| description | string | No | Longer explanation of this property |
| caseType | string | Yes | Reference to the parent case type |
| propertyType | string | No | Data type of this property |
| isRequired | boolean | No | Whether this property is required on cases |
| defaultValue | string | No | Default value for this property |

---

## result
**Schema.org type:** `schema:Thing`
**Purpose:** A case outcome record
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | No | Name of this result |
| case | string | Yes | Reference to the case |
| resultType | string | Yes | Reference to the result type |
| description | string | No | Description of this result |

**Relations:**
- → case (many-to-one)

---

## resultType
**Schema.org type:** `schema:Thing`
**Purpose:** Case outcome type with archival rules
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this result type (e.g. Vergunning verleend) |
| description | string | No | Description/toelichting of this result type |
| genericDescription | string | No | Generic description derived from selectielijst resultaattypeomschrijving |
| caseType | string | Yes | Reference to the parent case type |
| archivalPeriod | string | No | ISO 8601 duration for archival retention |
| archivalAction | string | No | What to do after archival period: keep or destroy |
| sourceDateArchiveProcedure | string | No | BrondatumArchiefprocedure configuration (JSON-encoded object with afleidingswijze, procestermijn, datumkenmerk, etc.) |
| selectionListClass | string | No | URL to the selectielijstklasse |

---

## role
**Schema.org type:** `schema:Role`
**Purpose:** A role assignment on a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Display name for this role assignment |
| roleType | string | Yes | Reference to the role type |
| case | string | Yes | Reference to the case |
| participant | string | Yes | Nextcloud user ID or contact reference |
| description | string | No | Description of this role assignment |

**Relations:**
- → case (many-to-one)

---

## roleType
**Schema.org type:** `schema:Role`
**Purpose:** Participant role type definition for a case type
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this role type (e.g. Behandelaar, Adviseur) |
| description | string | No | Description of this role type |
| caseType | string | Yes | Reference to the parent case type |

---

## statusRecord
**Schema.org type:** `schema:Event`
**Purpose:** A status transition record for a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case |
| statusType | string | Yes | Reference to the status type |
| description | string | No | Status transition description |

**Relations:**
- → case (many-to-one)

---

## statusType
**Schema.org type:** `schema:ActionStatusType`
**Purpose:** Status lifecycle phase definition for a case type
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this status (e.g. Ontvangen, In behandeling) |
| description | string | No | Description of this status phase |
| caseType | string | Yes | Reference to the parent case type |
| order | integer | Yes | Position in the status lifecycle (lower = earlier) |
| isFinal | boolean | No | Whether this is a terminal/final status |

---

## task
**Schema.org type:** `schema:Action`
**Purpose:** A task within a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Title of this task |
| description | string | No | Detailed description of this task |
| status | string | No | Task status (CMMN HumanTask lifecycle) |
| case | string | Yes | Reference to the parent case |
| assignee | string | No | Nextcloud user ID of the assigned user |
| dueDate | string | No | Due date for this task |
| priority | string | No | Task priority |
| completedDate | string | No | Date the task was completed |
| workflowStepId | string | No | UUID of the workflow step that generated this task |
| checklist | string | No | JSON-encoded array of checklist items ({id, label, checked}) |

**Relations:**
- → case (many-to-one)

---

## usageRights
**Schema.org type:** `schema:DigitalDocument`
**Purpose:** Usage rights (gebruiksrechten) for a document
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| document | string | Yes | URI reference to the document (EnkelvoudigInformatieObject) |
| startDate | string | Yes | Start date of the usage rights |
| endDate | string | No | End date of the usage rights |
| conditionsDescription | string | Yes | Description of the usage conditions |

---

## voorstel
**Schema.org type:** `schema:CreativeWork`
**Purpose:** A B&W voorstel (proposal) for decision-making in a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the parent case |
| type | string | Yes | Type of voorstel (DT-advies, Collegeadvies, Raadsvoorstel) |
| onderwerp | string | Yes | Subject of the voorstel (usually derived from case title) |
| steller | string | Yes | Nextcloud user UID who created the voorstel |
| afdeling | string | No | Department of the steller |
| portefeuillehouder | string | No | Nextcloud user UID of the responsible portfolio holder (wethouder) |
| status | string | Yes | Current status of the voorstel in the parafering lifecycle |
| parafeerroute | string | No | Reference to the parafeerroute being used |
| routeSnapshot | string | No | Snapshot of the parafeerroute steps at submission time (JSON-encoded array) |
| currentStep | integer | No | Current step number in the parafeerroute (1-based, 0 = not yet submitted) |
| returnedFromStep | integer | No | Step number from which the voorstel was returned (for resume on resubmit) |
| document | string | No | Nextcloud file ID of the primary voorstel document |
| bijlagen | array | No | Nextcloud file IDs of attached documents (bijlagen) |
| behandeling | string | No | Treatment type in the college meeting |
| decision | string | No | Reference to the linked decision (set when besluit is registered) |

**Relations:**
- → case (many-to-one)

---

## workflowTemplate
**Schema.org type:** `schema:HowTo`
**Purpose:** A workflow definition for a case type — defines process steps, status transitions, guards, and automatic actions
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Name of this workflow template |
| description | string | No | Purpose and usage notes for this workflow |
| caseType | string | Yes | Reference to the case type this workflow belongs to |
| version | integer | No | Auto-incrementing version number |
| isActive | boolean | No | Whether this is the active version for new cases |
| isDraft | boolean | No | Draft templates cannot be used for new cases |
| steps | string | No | JSON-encoded array of WorkflowStep objects. Each step has: id (UUID), title, description, status (UUID ref to statusType), order (integer), assigneeRole (UUID ref to roleType, optional), isRequired (boolean), checklist (array of {id, label, description}), automaticActions (array of ActionRef) |
| transitions | string | No | JSON-encoded array of StatusTransition objects. Each transition has: id (UUID), fromStatus (UUID), toStatus (UUID), label (string), guards (array of Guard), automaticActions (array of ActionRef), allowedRoles (array of UUID). Guard types: checklist, requiredField, requiredDocument, roleGuard. Action types: sendEmail, createTask, createSubCase, webhook, setField, notify |
| nodePositions | string | No | JSON-encoded map of status UUID to {x, y} canvas positions for the visual editor |
| parentWorkflow | string | No | Reference to parent workflow template for inheritance (Enterprise tier) |

**Relations:**
- → caseType (many-to-one)

---

## zaaktypeInformatieobjecttype
**Schema.org type:** `schema:Thing`
**Purpose:** Links a case type to a document type with direction and ordering
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| zaaktype | string | Yes | Reference to the case type |
| informatieobjecttype | string | Yes | Reference to the document type |
| volgnummer | integer | Yes | Ordering number |
| richting | string | Yes | Direction of the document in the case |
| statustype | string | No | Reference to a status type |

---
