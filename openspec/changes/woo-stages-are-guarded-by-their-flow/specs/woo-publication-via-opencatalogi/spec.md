# woo-publication-via-opencatalogi Delta: woo-stages-are-guarded-by-their-flow

**Status**: draft
**Scope**: publishing a Woo decision when nothing in the case can be made public (decision D2, 14 October).

## ADDED Requirements

### Requirement: The decision MUST be published when the case records that nothing is disclosable

When no document can be disclosed, publishing SHALL still publish the decision itself, as an opencatalogi publication without attachments, if the case records that nothing is disclosable. That record is either a recorded "nothing found" with its note, or every document under review assessed and every assessment `niet_openbaar`. In every other case an empty disclosable set SHALL still refuse with `no_publishable_documents`.

#### Scenario: Everything withheld

- **GIVEN** a Woo request whose two documents are both assessed `niet_openbaar`
- **WHEN** the coordinator publishes
- **THEN** opencatalogi receives one publication with no attachment
- **AND** the case reads `wooPublicationStatus: published`

#### Scenario: A redaction is still missing

- **GIVEN** one document `niet_openbaar` and one `deels_openbaar` without its redacted version
- **WHEN** the coordinator publishes
- **THEN** the publish is refused with `no_publishable_documents`
