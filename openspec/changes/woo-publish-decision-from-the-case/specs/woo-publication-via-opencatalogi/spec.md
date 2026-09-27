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
