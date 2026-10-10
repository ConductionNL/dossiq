# woo-publication-via-opencatalogi Specification

## Purpose
TBD - created by archiving change woo-publication-via-opencatalogi. Update Purpose after archive.

## Requirements

### Requirement: WOO publication payload building
The system MUST build a publication payload from an assembled WOO decision
and its per-document assessments, mapping the decision to a DIWOO
informatiecategorie and including only disclosable documents.

#### Scenario: Payload includes core besluit metadata
- **GIVEN** a WOO decision assembled via `WOODecisionService::assembleDecision()`
  for case `caseId` with `decisionDate`, `wooSummary`, and `weigeringsgronden`
- **WHEN** `WooPublicationService::buildPayload()` is called for that decision
- **THEN** the built payload MUST include title, summary, the decision date,
  and a case reference to `caseId`

#### Scenario: Decision maps to the Woo-verzoeken-en-besluiten informatiecategorie
- **GIVEN** a WOO decision with `decisionType` "WOO-besluit"
- **WHEN** `WooCategoryMapper::forDecision()` is called
- **THEN** it MUST return category code `infocat014` with label
  "Woo-verzoeken en -besluiten" and the TOOI URI
  `https://identifier.overheid.nl/tooi/def/thes/kern/c_3baef532`

#### Scenario: Unmapped decision type falls back to the Woo category
- **GIVEN** a decision whose `decisionType` has no explicit entry in the
  category mapping table
- **WHEN** `WooCategoryMapper::forDecision()` is called
- **THEN** it MUST still return the `infocat014` entry rather than throwing or
  returning null

### Requirement: Redacted-only document disclosure
The system MUST NEVER include an unredacted original document in a
publication payload for any document assessed as `deels_openbaar`, and MUST
NEVER include a document assessed as `niet_openbaar` at all.

#### Scenario: Openbaar document is included as-is
- **GIVEN** a document assessment with `classification: 'openbaar'`
- **WHEN** `WooPublicationService::selectDisclosableDocuments()` is called
- **THEN** the document MUST appear in the returned list using its normal
  content reference

#### Scenario: Deels openbaar document with a finalized redaction is included via the redacted reference only
- **GIVEN** a document assessment with `classification: 'deels_openbaar'` that
  has both an original content reference and a finalized redacted-version
  reference
- **WHEN** `WooPublicationService::selectDisclosableDocuments()` is called
- **THEN** the document MUST appear in the returned list
- **AND** the content reference used MUST be the redacted version
- **AND** the original content reference MUST NOT appear anywhere in the
  returned list

#### Scenario: Deels openbaar document without a finalized redaction is excluded
- **GIVEN** a document assessment with `classification: 'deels_openbaar'` and
  no finalized redacted-version reference (still `awaiting_manual_redaction`
  or queued at Docudesk)
- **WHEN** `WooPublicationService::selectDisclosableDocuments()` is called
- **THEN** the document MUST NOT appear in the returned list

#### Scenario: Niet openbaar document is always excluded
- **GIVEN** a document assessment with `classification: 'niet_openbaar'`
- **WHEN** `WooPublicationService::selectDisclosableDocuments()` is called
- **THEN** the document MUST NOT appear in the returned list regardless of
  any content reference it carries

### Requirement: Publish a WOO decision to OpenCatalogi

The system MUST create a publication (and its disclosable documents) in
OpenCatalogi's publication register when a case worker triggers publication
of an assembled WOO decision, and record the result on the dossiq decision
object through a single write.

The publication, its documents and their file bytes MUST be written through
OpenRegister **in process** (`ObjectServiceInterface` for objects,
`FileService` for file bytes), not through a self-addressed HTTP call. The
acting identity is therefore the session user under OpenRegister's default
`_rbac` / `_multitenancy` scoping, not a stored service account.

#### Scenario: Successful publish creates the publication and records the result

- **GIVEN** an assembled WOO decision with at least one disclosable document,
  and OpenCatalogi installed and enabled
- **WHEN** the case worker calls `POST /api/cases/{id}/woo/publish`
- **THEN** the system MUST create a publication object in OpenCatalogi's
  configured register/schema with the built payload
- **AND** create a `document` object for each disclosable document, linked to
  the publication
- **AND** write `wooPublication.publicationId`, `.publicationUrl`, `.status`
  ("published"), `.category`, and `.publishedAt` onto the dossiq decision
  object via exactly one `ObjectService::saveObject()` call
- **AND** return the publication id and url in the response

#### Scenario: Publish is idempotent per decision

- **GIVEN** a WOO decision that was already published (has
  `wooPublication.publicationId` set)
- **WHEN** the case worker calls `POST /api/cases/{id}/woo/publish` again
- **THEN** the system MUST update the existing publication rather than create
  a duplicate
- **AND** the update MUST preserve every stored publication field the new
  payload does not name

#### Scenario: No disclosable documents blocks publish with a clear reason

- **GIVEN** a WOO decision where every document is `niet_openbaar` or
  `deels_openbaar`-pending-redaction
- **WHEN** the case worker calls `POST /api/cases/{id}/woo/publish`
- **THEN** the system MUST NOT create a publication
- **AND** the response MUST report `available: false` with reason
  `no_publishable_documents`

### Requirement: OpenCatalogi absence is handled gracefully
The system MUST NOT hard-fail the WOO case flow when OpenCatalogi is not
installed or not enabled, and MUST surface an actionable admin hint instead.

#### Scenario: OpenCatalogi not installed
- **GIVEN** the `opencatalogi` app is not installed or not enabled for the
  current user on this Nextcloud instance
- **WHEN** the case worker calls `POST /api/cases/{id}/woo/publish`
- **THEN** the system MUST return `available: false` with reason
  `opencatalogi_not_installed`
- **AND** MUST NOT throw an unhandled exception or corrupt the case's
  existing decision data

#### Scenario: OpenRegister unavailable
- **GIVEN** `SettingsService::getObjectService()` returns null (OpenRegister
  unavailable)
- **WHEN** `WooPublicationService::checkAvailability()` is called
- **THEN** it MUST return `available: false` with reason
  `openregister_unavailable`

### Requirement: Withdraw a published WOO decision

The system MUST support withdrawing (depublishing) a previously published WOO
decision, marking it withdrawn both in OpenCatalogi and on the dossiq
decision object.

Withdrawal sends a single-key partial payload to OpenCatalogi's publication
object. Because OpenRegister's published write is PUT-semantic, that write
MUST be performed as a read-merge-write; a bare save of the partial payload
would null every other property of the publication while reporting success.

#### Scenario: Withdraw a published decision

- **GIVEN** a WOO decision with `wooPublication.status` "published" and a
  known `publicationId`
- **WHEN** the case worker calls `POST /api/cases/{id}/woo/withdraw`
- **THEN** the system MUST set the OpenCatalogi publication's depublication
  date to now
- **AND** the publication MUST retain its title, summary, publication date and
  category
- **AND** update `wooPublication.status` to "withdrawn" and set
  `wooPublication.withdrawnAt` on the dossiq decision object via one
  `ObjectService::saveObject()` call

#### Scenario: Withdraw without a prior publish is rejected

- **GIVEN** a WOO decision with no `wooPublication.publicationId`
- **WHEN** the case worker calls `POST /api/cases/{id}/woo/withdraw`
- **THEN** the system MUST return an error indicating there is nothing to
  withdraw, and MUST NOT call OpenCatalogi

### Requirement: Publish action authorization

The publish and withdraw endpoints MUST enforce per-case mutation authorization
that fails closed. The previous wording required rejection only for a non-member
of the `procest-gebruikers` group **"(when that group exists)"**, which
specified a fail-open control: the group never existed, so the guard
short-circuited and every authenticated user was authorized. Group existence
MUST play no part in the authorization decision.

#### Scenario: Unauthenticated request is rejected

@e2e exclude Authentication boundary enforced by Nextcloud middleware ahead of the controller; covered by `AdviceServiceAuthorizationTest::testUnauthenticatedCallerIsRejected` and, live, by the unauthenticated arm of the two-account probe recorded on PR #805 (HTTP 401).

- **GIVEN** no authenticated user session
- **WHEN** `POST /api/cases/{id}/woo/publish` is called
- **THEN** the system MUST return 401 Unauthorized

#### Scenario: Authenticated non-authorized user is rejected

@e2e exclude Backend authorisation boundary needing a second logged-in session; covered by `WOOAssessmentControllerAuthorizationTest::testAuthenticatedNonAssigneeIsRejectedFromEveryMutationEndpoint`, which drives the publish and withdraw endpoints named by this requirement.

- **GIVEN** an authenticated user who is not an admin and is not the case
  assignee
- **WHEN** `POST /api/cases/{id}/woo/publish` is called
- **THEN** the system MUST reject with a forbidden response, via
  `CaseAccessGuard::assertCaseMutationAccess()`
- **AND** rejection MUST NOT depend on whether any group exists

### Requirement: Publication status surfaced on the WOO assessment view
The system MUST surface the publish action, the withdraw action and the
current publication status and link on the case page of a Woo case. The case
carries `wooPublicationStatus` (`none`, `ready`, `published`, `withdrawn`) and
`wooPublicationUrl`, written only by the decision assembly, the publish and the
withdraw. The assessment view this requirement named before was removed as
unreachable (#867).

#### Scenario: Unpublished decision shows a publish action
@e2e tests/e2e/woo-publish-from-the-case.spec.ts
- **GIVEN** a Woo case whose decision is assembled and not published
- **WHEN** the handler opens the case page
- **THEN** the header MUST offer "Publish (Woo)"
- **AND** the Data tab MUST show the publication status as ready

#### Scenario: Published decision shows its status and link
@e2e tests/e2e/woo-publish-from-the-case.spec.ts
- **GIVEN** a Woo case whose decision is published
- **WHEN** the handler opens the case page
- **THEN** the Data tab MUST show the status published and a link to the
  publication in OpenCatalogi
- **AND** the header MUST offer "Withdraw publication" and MUST NOT offer
  "Publish (Woo)"

### Requirement: REQ-WPI-001 — Publication objects MUST be written through OpenRegister's published in-process contract, not over HTTP

`OpenCatalogiApiClient` MUST create and update publication and document
objects by calling OpenRegister's `ObjectServiceInterface` (ADR-084) in
process. It MUST NOT build or fetch an OpenRegister objects-API URL with
`IClientService` (ADR-080 D2/D3). The service MUST be resolved through
`SettingsService::getObjectService()`, which returns `null` when OpenRegister
is absent.

Only methods published on `ObjectServiceInterface` may be used. Every call
MUST pass its arguments by name.

#### Scenario: Creating a publication saves an object instead of posting a URL

- **GIVEN** OpenCatalogi's publication register/schema are configured
- **WHEN** `OpenCatalogiApiClient::createPublication()` is called with a payload
- **THEN** it MUST call `saveObject(object: <payload>, register: <register>, schema: <schema>, uuid: null)` on the OpenRegister object service
- **AND** it MUST NOT perform any HTTP request
- **AND** it MUST return the stored object as an associative array carrying the object's `id`

#### Scenario: Creating a linked document saves an object instead of posting a URL

- **GIVEN** a publication id
- **WHEN** `OpenCatalogiApiClient::attachDocument()` is called with a document payload
- **THEN** it MUST call `saveObject(object: <payload>, register: <register>, schema: <document schema>, uuid: null)`
- **AND** it MUST NOT perform any HTTP request

#### Scenario: The OpenRegister objects-API URL is gone from the client

- **GIVEN** the shipped `lib/Service/WooPublication/OpenCatalogiApiClient.php`
- **WHEN** its source is read
- **THEN** it MUST contain no `/apps/openregister/api/objects/` path in executable code

### Requirement: REQ-WPI-002 — A partial publication update MUST be a read-merge-write, never a bare save

`updatePublication()` receives a PARTIAL payload — `withdraw()` sends only
`depublicatiedatum`. `ObjectServiceInterface::saveObject()` is PUT-semantic: a
property absent from the payload is written as null.
`ObjectServiceInterface::updateObject()` does not help — despite a docblock
reading "Apply a partial update to an existing object", its implementation
assigns `$data['id']` and calls `saveObject()` with no merge, and the one
method that really merges (`patchObject()`) is not published on the contract.

`updatePublication()` MUST therefore read the stored object, shallow-merge the
partial payload over it, and save the merged result under the same uuid —
reproducing what OpenRegister's own `objects#patch` route does. It MUST NOT
call `updateObject()`.

The read MUST use `findSilent()` so a publication update does not write a
spurious read entry into the audit trail, and MUST leave `_rbac` and
`_multitenancy` at their contract defaults.

#### Scenario: Withdrawing a publication preserves every field it did not name

- **GIVEN** a stored publication carrying `title`, `summary`, `publicationDate`, `tooiCategorieUri` and `status`
- **WHEN** `updatePublication()` is called with the single-key payload `{ depublicatiedatum: <now> }`
- **THEN** the object saved back MUST carry `depublicatiedatum` set to that value
- **AND** it MUST still carry the original `title`, `summary`, `publicationDate`, `tooiCategorieUri` and `status`

#### Scenario: A key present in both the stored object and the payload takes the payload's value

- **GIVEN** a stored publication with `status: "published"`
- **WHEN** `updatePublication()` is called with `{ status: "withdrawn" }`
- **THEN** the object saved back MUST carry `status: "withdrawn"`

#### Scenario: The merged object is saved under the same uuid

- **GIVEN** an existing publication with uuid `pub-001`
- **WHEN** `updatePublication()` is called for `pub-001`
- **THEN** `saveObject()` MUST be called with `uuid: "pub-001"`, so the write updates that object rather than creating a second one

### Requirement: REQ-WPI-003 — File bytes MUST be attached in process, and the transport failure contract MUST be unchanged

`attachFile()` MUST attach file content through OpenRegister's
`FileService::addFile()` resolved from the DI container, rather than posting to
OpenRegister's per-object files route. `ObjectServiceInterface` publishes no
file operation, so this is the only in-process route available; the gap is
recorded in the proposal.

`attachFile()`'s `$mimeType` parameter MUST be kept for call-shape
compatibility and MUST be documented as unused by OpenRegister — the HTTP
route it replaces also ignored it, because `FileService::addFile()` takes no
MIME argument.

Every failure of any operation on this client MUST continue to surface as
`RuntimeException` with message `opencatalogi_api_error`, so
`WooPublicationService`'s existing `catch (Throwable)` arms — which map it to
`['available' => false, 'reason' => 'opencatalogi_api_error']` — keep working
unchanged.

#### Scenario: Attaching file bytes calls the in-process file service

- **GIVEN** a created document object with id `doc-001` and base64 file content
- **WHEN** `attachFile()` is called
- **THEN** it MUST call `addFile(objectEntity: "doc-001", fileName: <name>, content: <base64>, share: false, tags: [])`
- **AND** it MUST NOT perform any HTTP request

#### Scenario: An OpenRegister failure is reported as the existing domain error

- **GIVEN** the OpenRegister object service throws on `saveObject()`
- **WHEN** `createPublication()` is called
- **THEN** it MUST throw `RuntimeException` with message `opencatalogi_api_error`

#### Scenario: OpenRegister being unavailable is reported as the existing domain error

- **GIVEN** `SettingsService::getObjectService()` returns null
- **WHEN** `createPublication()` is called
- **THEN** it MUST throw `RuntimeException` with message `opencatalogi_api_error`
- **AND** it MUST NOT dereference the null service

### Requirement: REQ-WPI-004 — Catalog discovery stays an OpenCatalogi HTTP read and MUST keep its swallow-and-continue contract

`resolveCatalog()` reads OpenCatalogi's own catalog listing
(`/index.php/apps/opencatalogi/api/catalogi`), which is not OpenRegister's
Objects API and is therefore outside ADR-080 D2/D3. It MUST keep using
`IClientService`, MUST keep sending the configured service-account credentials
when both are set, and MUST keep swallowing every failure and returning `null`
so discovery never gates publication.

#### Scenario: Discovery still returns the first WOO-flagged catalog

- **GIVEN** OpenCatalogi answers with a list containing a catalog whose `hasWooSitemap` is true
- **WHEN** `resolveCatalog()` is called
- **THEN** it MUST return that catalog

#### Scenario: A discovery transport failure returns null rather than throwing

- **GIVEN** the HTTP client throws
- **WHEN** `resolveCatalog()` is called
- **THEN** it MUST return `null` and MUST NOT throw

### Requirement: The publish endpoints find the case's Woo decision (REQ-WPI-005)
`POST /api/cases/{id}/woo/publish` and `POST /api/cases/{id}/woo/withdraw` MUST
accept a request without `decisionId`. They MUST then use the one `decision` on
the case that carries a `wooSummary`. With none they MUST answer 409
`no_woo_decision`; with more than one they MUST answer 409
`several_woo_decisions` and name the decision ids. Authorization MUST stay as
"Publish action authorization" states.

#### Scenario: Publish without a decision id
@e2e tests/e2e/woo-publish-from-the-case.spec.ts
- **GIVEN** a Woo case with one assembled decision and one document assessed as
  public
- **WHEN** the handler calls `POST /api/cases/{id}/woo/publish` with an empty body
- **THEN** the system MUST publish that decision to OpenCatalogi
- **AND** the case MUST read `wooPublicationStatus` published

#### Scenario: No Woo decision yet
@e2e tests/e2e/woo-publish-from-the-case.spec.ts
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
@e2e exclude Needs a running OpenRegister (live pass, decision 139). Covered by `WooPublicationFieldsShippedTest::testTheDecisionDeclaresEveryFieldTheWooCodeWrites`.
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
@e2e exclude Needs OpenCatalogi. Covered by `WooPublicationJourneyTest::testPublishWithoutADecisionIdSendsTheJourneyFields` and `WooPublishOnTheRealRegisterTest`.
- **GIVEN** a decided Woo request case for the period 2025 with two public documents
- **WHEN** the Woo coordinator publishes it
- **THEN** the publication MUST carry `publicationKind` woo-besluit, `wooCategory` infocat014, the case uuid and the period
- **AND** both documents MUST be files on the publication

#### Scenario: A withdrawn decision stops being public
@e2e exclude Needs OpenCatalogi. Covered by `WooPublicationJourneyTest::testWithdrawSetsDepublicationDate`.
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
@e2e exclude Needs OpenCatalogi and a portal dossier. Covered by `WooDossierReturnTest::testThePublicationIsAddedByDossiq` and `testTheItemFitsTheCollectionItemSchema`.
- **GIVEN** a Woo request started from a dossier
- **WHEN** its decision is published
- **THEN** the dossier MUST hold the new publication, added by dossiq
- **AND** the resident's case MUST carry the publication link that portaliq's change rule reports

#### Scenario: Republishing does not add the item twice
@e2e exclude Needs OpenCatalogi. Covered by `WooDossierReturnTest::testRepublishingAddsNothing`.
- **GIVEN** a decision already published and added to the dossier
- **WHEN** it is published again
- **THEN** the dossier MUST still hold one item for that publication

#### Scenario: A request without a dossier
@e2e exclude Needs OpenCatalogi. Covered by `WooDossierReturnTest::testARequestWithoutADossierWritesNothing`.
- **GIVEN** a Woo request case with no `wooRequest.collectionId`
- **WHEN** its decision is published
- **THEN** the publication MUST be created and no collection MUST be written

### Requirement: The publish action shows only to whoever may publish, and says what happened (REQ-WPI-009)
The case page MUST offer "Publish (Woo)" and "Withdraw publication" only to the
case's assignee. The endpoints also let an admin through ("Publish action
authorization"); an admin who does not handle the case claims it first. The
gate MUST be decided from the case itself, without a request to the server,
so the header never shows the action while a request is pending.
Publishing MUST ask for confirmation first. A refusal MUST show the server's
sentence in the user's language. Once the decision is published, the case
header MUST offer a link to the publication, labelled "Published: view the
publication" ("Gepubliceerd: publicatie bekijken"), and MUST NOT offer
"Publish (Woo)". Hiding the action is not an authorization: the endpoints keep
their own check.

#### Scenario: A colleague who does not handle the case does not see the action
- **GIVEN** a Woo case whose decision is ready, assigned to another handler
- **WHEN** a user who is not its assignee opens the case page
- **THEN** the header MUST NOT offer "Publish (Woo)" or "Withdraw publication"
@e2e exclude rendered through the built CnActionButtons with the real headerActions; tests/vitest/wooPublishHeaderBar.spec.js "hides Publish from a colleague who does not handle the case"

#### Scenario: A published case never offers Publish, even while the page is still loading
- **GIVEN** a Woo case whose decision is published, and a header still waiting for its server requests
- **WHEN** anyone opens the header menu
- **THEN** the menu MUST NOT offer "Publish (Woo)"
- **AND** it MUST offer "Published: view the publication", linking to `wooPublicationUrl`
@e2e exclude rendered through the built CnActionButtons with every endpoint request held open; tests/vitest/wooPublishHeaderBar.spec.js "shows only the link on a case someone else published, never Publish"

#### Scenario: The handler publishes after a confirmation and gets the link
- **GIVEN** a Woo case whose decision is ready, assigned to the handler
- **WHEN** the handler presses "Publish (Woo)" and confirms
- **THEN** the decision MUST be published through `POST /api/cases/{id}/woo/publish`
- **AND** the header MUST offer "Withdraw publication" and "Published: view the publication", and MUST NOT offer "Publish (Woo)"
@e2e exclude rendered through the built CnActionButtons; tests/vitest/wooPublishHeaderBar.spec.js "offers Publish (Woo) to the handler of a ready case" and "swaps Publish for Withdraw and the link once the handler has published"

#### Scenario: A refusal reads in the user's language
- **GIVEN** a Woo case whose decision is ready and no document assessed as public
- **WHEN** a Dutch-speaking handler presses "Publish (Woo)" and confirms
- **THEN** the system MUST answer 409 with the message "Er kan nog niets gepubliceerd worden: geen enkel document is als openbaar beoordeeld."
@e2e exclude unit over the controller; WOOAssessmentControllerTest::testARefusalIsTranslatedForTheHeaderAction
