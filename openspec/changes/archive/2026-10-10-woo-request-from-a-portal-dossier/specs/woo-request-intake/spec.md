# woo-request-intake

## ADDED Requirements

### Requirement: The Woo request case type is seeded (REQ-WRI-001)
dossiq MUST seed a case type with identifier `woo-verzoek`, a processing
deadline of 4 weeks (`P28D`) extendable by 2 weeks (`P14D`), the eight Woo
statuses from `Ontvangst` to `Afgehandeld`, the result types openbaar gemaakt,
deels openbaar gemaakt, niet openbaar gemaakt and ingetrokken, and portal
windows open in its first two statuses. Implements hydra `openspec/changes/woo-citizen-journey/specs/woo-citizen-journey/spec.md`,
"A Woo request MUST be created by one dossiq path, from the portal and from
pipelinq alike" (the case "shows under Mijn zaken with its legal deadline").

#### Scenario: A fresh install has a Woo request case type
- **GIVEN** dossiq installed with its register imported
- **WHEN** an admin opens the case types
- **THEN** "Woo-verzoek" MUST be listed with eight statuses and four result types
- **AND** a new case of this type MUST get a deadline 28 days after its start

### Requirement: One service creates every Woo request (REQ-WRI-002)
`OCA\Dossiq\Woo\WooRequestIntake::start(array $request): array` MUST be the
only code that opens a Woo request for a resident. It MUST take
`{subjectRef, collectionId?, onderwerp, omschrijving, periodeVan, periodeTot,
origin, originReference}`, with `origin` `portal` or `pipelinq`, and return
`{caseId, caseUrl}`. It MUST open a case of the seeded Woo type with
`portalSubject` equal to `subjectRef` and `wooRequest` holding the request,
and add one `caseObject` per dossier item with `objectType`
`opencatalogi.publication`, `objectUrl` the public publication URL and
`objectIdentification` naming the publication and the attachment. Implements
hydra `openspec/changes/woo-citizen-journey/specs/woo-citizen-journey/spec.md`, "A Woo request MUST be created by one dossiq path, from the portal and
from pipelinq alike".

#### Scenario: A resident starts a request from a dossier
- **GIVEN** a resident's dossier with two publications
- **WHEN** `start()` runs with that dossier, an onderwerp and `origin: portal`
- **THEN** a Woo request case MUST exist with `portalSubject` the resident's subjectRef
- **AND** it MUST have two case objects of type `opencatalogi.publication` pointing at those publications

#### Scenario: An employee converts a question
- **GIVEN** a ticket asked from a dossier
- **WHEN** pipelinq calls `start()` with `origin: pipelinq` and the ticket as `originReference`
- **THEN** the case MUST be the same kind of case, for the same resident, with the dossier's items as case objects

#### Scenario: A request without a dossier
- **GIVEN** no `collectionId`
- **WHEN** `start()` runs
- **THEN** the case MUST be opened without case objects

### Requirement: Only the owner's dossier starts a request (REQ-WRI-003)
When `collectionId` is given, `start()` MUST read the opencatalogi
`collection` and refuse with `not_found` when it does not exist or its
`owner` is not `subjectRef`, before anything is written. Implements hydra `openspec/changes/woo-citizen-journey/specs/woo-citizen-journey/spec.md`,
"A resident's dossier MUST be owned by the resident and readable by nobody
else unless shared".

#### Scenario: Someone else's dossier
- **GIVEN** a dossier owned by another subject
- **WHEN** `start()` runs with its id
- **THEN** it MUST refuse with `not_found`, the same as for an id that does not exist
- **AND** no case MUST be written

### Requirement: The dossier records the request it started (REQ-WRI-004)
After the case is written, `start()` MUST append `dossiq:case:{caseUuid}` to
the collection's `sourceOf`, once, and MUST NOT change or remove any item.
Implements hydra `openspec/changes/woo-citizen-journey/specs/woo-citizen-journey/spec.md`, "A decision on a request started from a dossier MUST come
back to that dossier" (the link it comes back by).

#### Scenario: The dossier knows the request
- **GIVEN** a request started from a dossier
- **WHEN** the dossier is read
- **THEN** its `sourceOf` MUST hold the case reference
- **AND** its items MUST be unchanged
