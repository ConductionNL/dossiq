# woo-publication-via-opencatalogi delta: the-woo-publication-carries-its-documents

## ADDED Requirements

### Requirement: The publication carries every disclosable file, or is not made (REQ-WPI-010)

Publishing a Woo decision MUST attach the file of every document assessed `openbaar` and the redacted copy of every document assessed `deels_openbaar`, and nothing else. A case file MUST be read from OpenRegister's managed tree, where it lives, whoever the caller is, and from the root folder only when the managed tree does not know it. When the file of a document that would be disclosed cannot be read, the publish MUST be refused with 409 and the reason `documents_unreadable`, naming those documents, and no publication MUST be created or changed.

#### Scenario: The handler publishes a case with a public and a redacted document
@e2e exclude {needs OpenCatalogi and OpenRegister's managed file tree; asserted in tests/Unit/Woo/WooPublicationCarriesItsDocumentsTest.php with a root folder that finds nothing, as it did for the handler in rehearsal R3}

- **GIVEN** a Woo request with a document assessed Openbaar, a document assessed Deels openbaar with a redacted copy, one Deels openbaar without a copy, and one Niet openbaar
- **AND** the handler has no mount on the folder that holds the case files
- **WHEN** the handler publishes the decision
- **THEN** the publication MUST carry the public document and the redacted copy
- **AND** it MUST NOT carry the original of either partly public document or the withheld document

#### Scenario: A file that cannot be read stops the publish
@e2e exclude {asserted in tests/Unit/Woo/WooPublicationCarriesItsDocumentsTest.php and tests/Unit/Controller/WOOAssessmentControllerTest.php}

- **GIVEN** a document assessed Openbaar whose file cannot be read
- **WHEN** the handler publishes the decision
- **THEN** the answer MUST be 409 with "Een document dat openbaar mag worden kon niet worden gelezen, dus er is niets gepubliceerd."
- **AND** the case MUST still read ready, not published

## MODIFIED Requirements

### Requirement: A decision comes back to the dossier it was asked from (REQ-WPI-008)
When the case has `wooRequest.collectionId`, publishing MUST append one item
`{id, publication, attachment: null, note, addedAt, addedBy: "dossiq"}` to that
collection, once per publication, without changing or removing any other item.
The collection lives in another app's register and the resident's tenant, so
it MUST be read and written unscoped, and only while its `owner` is the case's
`portalSubject`, the check the intake made; a collection that is not the
requester's MUST get nothing. The case MUST then read `wooPublicationUrl`, and the citizen contribution MUST
declare the change rule `dossiq.wooRequest.published` on `mijnZaken` for that
field, so portaliq writes the resident's notice in the portal inbox and sends
it by email as the resident prefers. It MUST NOT be sent to the Berichtenbox,
which needs a BSN this journey does not store. A failure to reach the collection MUST be
logged and MUST NOT undo the publication. Implements hydra `openspec/changes/woo-citizen-journey/specs/woo-citizen-journey/spec.md`, "A decision on a
request started from a dossier MUST come back to that dossier" and "Every
answer, decision and alert MUST reach the resident through portaliq's notice
path".

#### Scenario: The resident finds the decision in their dossier
@e2e exclude Needs OpenCatalogi and a portal dossier. Covered by `WooDossierReturnTest::testThePublicationIsAddedByDossiq` and `testTheItemFitsTheCollectionItemSchema`, on a store that hides the dossier from a scoped read.
- **GIVEN** a Woo request started from a dossier, handled by a signed-in handler who cannot see that dossier under their own scope
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

#### Scenario: A dossier that is not the requester's gets nothing
@e2e exclude Covered by `WooDossierReturnTest::testADossierOfSomebodyElseGetsNothing`.
- **GIVEN** a case whose `wooRequest.collectionId` names a collection owned by somebody else
- **WHEN** its decision is published
- **THEN** that collection MUST NOT change
