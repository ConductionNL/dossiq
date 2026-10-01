## MODIFIED Requirements

### Requirement: Publication status surfaced on the WOO assessment view
The system MUST surface the publish action, the withdraw action and the
current publication status and link on the case page of a Woo case. The case
carries `wooPublicationStatus` (`none`, `ready`, `published`, `withdrawn`) and
`wooPublicationUrl`, written only by the decision assembly, the publish and the
withdraw. The assessment view this requirement named before was removed as
unreachable (#867).

#### Scenario: Unpublished decision shows a publish action
- **GIVEN** a Woo case whose decision is assembled and not published
- **WHEN** the handler opens the case page
- **THEN** the header MUST offer "Publish (Woo)"
- **AND** the Data tab MUST show the publication status as ready

#### Scenario: Published decision shows its status and link
- **GIVEN** a Woo case whose decision is published
- **WHEN** the handler opens the case page
- **THEN** the Data tab MUST show the status published and a link to the
  publication in OpenCatalogi
- **AND** the header MUST offer "Withdraw publication" and MUST NOT offer
  "Publish (Woo)"

## ADDED Requirements

### Requirement: The publish endpoints find the case's Woo decision (REQ-WPI-005)
`POST /api/cases/{id}/woo/publish` and `POST /api/cases/{id}/woo/withdraw` MUST
accept a request without `decisionId`. They MUST then use the one `decision` on
the case that carries a `wooSummary`. With none they MUST answer 409
`no_woo_decision`; with more than one they MUST answer 409
`several_woo_decisions` and name the decision ids. Authorization MUST stay as
"Publish action authorization" states.

#### Scenario: Publish without a decision id
- **GIVEN** a Woo case with one assembled decision and one document assessed as
  public
- **WHEN** the handler calls `POST /api/cases/{id}/woo/publish` with an empty body
- **THEN** the system MUST publish that decision to OpenCatalogi
- **AND** the case MUST read `wooPublicationStatus` published

#### Scenario: No Woo decision yet
- **GIVEN** a Woo case with no assembled decision
- **WHEN** the handler presses "Publish (Woo)" or calls the endpoint without a
  decision id
- **THEN** the system MUST answer 409 `no_woo_decision`
- **AND** nothing MUST be sent to OpenCatalogi

### Requirement: The decision schema declares the Woo fields its writers send (REQ-WPI-006)
The `decision` schema MUST declare `wooPublication`, `wooSummary`,
`weigeringsgronden`, `assessmentCount` and `decidedBy`, so a saved Woo decision
keeps its publication id, url and status.

#### Scenario: A publication id survives a read
- **GIVEN** a Woo decision that was just published
- **WHEN** any client reads the decision through OpenRegister's objects API
- **THEN** the response MUST carry `wooPublication.publicationId` and
  `wooPublication.status` published

### Requirement: The publication carries the Woo journey fields (REQ-WPI-007)
A publication created from a case MUST carry `publicationKind` `woo-besluit`,
`wooCategory` (opencatalogi's existing information category, `infocat014`
for a Woo decision), `caseReference` (the case
uuid), `period` `{from, to}` from the request's `periodeVan` and `periodeTot`,
and a `publicationDate` of the moment it is published. The disclosable
documents MUST be files attached to the publication object itself. Withdrawing
MUST set the schema's `depublicationDate`. Implements hydra `openspec/changes/woo-citizen-journey/specs/woo-citizen-journey/spec.md`, "Both publishing
paths MUST create a public, searchable publication".

#### Scenario: A published decision has its category, period and documents
- **GIVEN** a decided Woo request case for the period 2025 with two public documents
- **WHEN** the Woo coordinator publishes it
- **THEN** the publication MUST carry `publicationKind` woo-besluit, `wooCategory` infocat014, the case uuid and the period
- **AND** both documents MUST be files on the publication

#### Scenario: A withdrawn decision stops being public
- **GIVEN** a published decision
- **WHEN** the Woo coordinator withdraws it
- **THEN** the publication's `depublicationDate` MUST be set

### Requirement: A decision comes back to the dossier it was asked from (REQ-WPI-008)
When the case has `wooRequest.collectionId`, publishing MUST append one item
`{id, publication, attachment: null, note, addedAt, addedBy: "dossiq"}` to that
collection, once per publication, without changing or removing any other item.
The case MUST then read `wooPublicationUrl`, and the citizen contribution MUST
declare the change rule `dossiq.wooRequest.published` on `mijnZaken` for that
field, so portaliq writes the resident's notice in the portal inbox and sends
it by email as the resident prefers. It MUST NOT be sent to the Berichtenbox,
which needs a BSN this journey does not store. A failure to reach the collection MUST be
logged and MUST NOT undo the publication. Implements hydra `openspec/changes/woo-citizen-journey/specs/woo-citizen-journey/spec.md`, "A decision on a
request started from a dossier MUST come back to that dossier" and "Every
answer, decision and alert MUST reach the resident through portaliq's notice
path".

#### Scenario: The resident finds the decision in their dossier
- **GIVEN** a Woo request started from a dossier
- **WHEN** its decision is published
- **THEN** the dossier MUST hold the new publication, added by dossiq
- **AND** the resident's case MUST carry the publication link that portaliq's change rule reports

#### Scenario: Republishing does not add the item twice
- **GIVEN** a decision already published and added to the dossier
- **WHEN** it is published again
- **THEN** the dossier MUST still hold one item for that publication

#### Scenario: A request without a dossier
- **GIVEN** a Woo request case with no `wooRequest.collectionId`
- **WHEN** its decision is published
- **THEN** the publication MUST be created and no collection MUST be written

### Requirement: The publish action shows only to whoever may publish, and says what happened (REQ-WPI-009)
The case page MUST offer "Publish (Woo)" and "Withdraw publication" only to a
user the endpoints let through: the case's assignee or an admin, as
"Publish action authorization" states. Publishing MUST ask for confirmation
first. A refusal MUST show the server's sentence in the user's language. Once
the decision is published, the case header MUST offer a link to the
publication, labelled "Published: view the publication" ("Gepubliceerd:
publicatie bekijken"), in place of "Publish (Woo)". Hiding the action is not
an authorization: the endpoints keep their own check.

#### Scenario: A colleague who may not publish does not see the action
- **GIVEN** a Woo case whose decision is ready, assigned to another handler
- **WHEN** a user who is neither its assignee nor an admin opens the case page
- **THEN** the header MUST NOT offer "Publish (Woo)" or "Withdraw publication"
@e2e exclude evaluated through the library's own evaluateVisibleWhen; tests/vitest/wooPublishManifest.spec.js "hides from a colleague who neither handles the case nor is an admin"

#### Scenario: The handler publishes after a confirmation and gets the link
- **GIVEN** a Woo case whose decision is ready, assigned to the handler
- **WHEN** the handler presses "Publish (Woo)" and confirms
- **THEN** the decision MUST be published through `POST /api/cases/{id}/woo/publish`
- **AND** the header MUST offer "Published: view the publication", linking to `wooPublicationUrl`, and MUST NOT offer "Publish (Woo)"
@e2e exclude evaluated through the library's own evaluateVisibleWhen and interpolateActionTarget; tests/vitest/wooPublishManifest.spec.js "after publishing swaps Publish for the link to the publication"

#### Scenario: A refusal reads in the user's language
- **GIVEN** a Woo case whose decision is ready and no document assessed as public
- **WHEN** a Dutch-speaking handler presses "Publish (Woo)" and confirms
- **THEN** the system MUST answer 409 with the message "Er kan nog niets gepubliceerd worden: geen enkel document is als openbaar beoordeeld."
@e2e exclude unit over the controller; WOOAssessmentControllerTest::testARefusalIsTranslatedForTheHeaderAction
