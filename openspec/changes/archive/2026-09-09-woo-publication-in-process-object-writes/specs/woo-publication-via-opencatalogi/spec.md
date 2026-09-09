# WOO publication — in-process OpenRegister object writes

## ADDED Requirements

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

## MODIFIED Requirements

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
