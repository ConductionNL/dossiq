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

#### Scenario: Withdraw a published decision
- **GIVEN** a WOO decision with `wooPublication.status` "published" and a
  known `publicationId`
- **WHEN** the case worker calls `POST /api/cases/{id}/woo/withdraw`
- **THEN** the system MUST set the OpenCatalogi publication's depublication
  date to now
- **AND** update `wooPublication.status` to "withdrawn" and set
  `wooPublication.withdrawnAt` on the dossiq decision object via one
  `ObjectService::saveObject()` call

#### Scenario: Withdraw without a prior publish is rejected
- **GIVEN** a WOO decision with no `wooPublication.publicationId`
- **WHEN** the case worker calls `POST /api/cases/{id}/woo/withdraw`
- **THEN** the system MUST return an error indicating there is nothing to
  withdraw, and MUST NOT call OpenCatalogi

### Requirement: Publish action authorization
The publish and withdraw endpoints MUST enforce the same per-case mutation
authorization as the existing WOO assessment and decision endpoints.

#### Scenario: Unauthenticated request is rejected
- **GIVEN** no authenticated user session
- **WHEN** `POST /api/cases/{id}/woo/publish` is called
- **THEN** the system MUST return 401 Unauthorized

#### Scenario: Authenticated non-authorized user is rejected
- **GIVEN** an authenticated user who is not an admin and not a member of the
  `procest-gebruikers` group (when that group exists)
- **WHEN** `POST /api/cases/{id}/woo/publish` is called
- **THEN** the system MUST reject with a forbidden response, matching
  `WOOAssessmentController::requireCaseMutationAccess()`'s existing guard

### Requirement: Publication status surfaced on the WOO assessment view
The system MUST surface the publish action and current publication
status/link on the existing WOO document-assessment view.

#### Scenario: Unpublished decision shows a publish action
- **GIVEN** a WOO case with an assembled decision that has not been published
- **WHEN** the case worker views `DocumentAssessmentPanel.vue`
- **THEN** a "Publish (Woo)" action MUST be visible

#### Scenario: Published decision shows its status and link
- **GIVEN** a WOO decision with `wooPublication.status` "published" and a
  `publicationUrl`
- **WHEN** the case worker views the WOO assessment view
- **THEN** the published status MUST be shown along with a link to the
  publication

