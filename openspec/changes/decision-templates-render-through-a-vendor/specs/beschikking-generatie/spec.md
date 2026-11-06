## ADDED Requirements

### Requirement: A decision template may render through a document vendor (REQ-BG-VENDOR-01)

A decision template SHALL be able to name a vendor engine (SmartDocuments or Xential, as integriq sources), and generating a decision from such a template SHALL send the case data to that vendor through filinq and store the returned document on the case. When the vendor source is unreachable, generation SHALL fail with a message naming the source and SHALL NOT fall back to the mock.

#### Scenario: A decision renders through SmartDocuments

- **GIVEN** a decision template whose engine is `vendor:smartdocuments` and a reachable SmartDocuments source in integriq
- **WHEN** the handler generates the decision on a case
- **THEN** the document returned by SmartDocuments SHALL be stored on the case as the decision document
- @e2e exclude the vendor is an external system; asserted against integriq's mock source in Newman

#### Scenario: An unreachable vendor fails loudly

- **GIVEN** a template on `vendor:xential` and the Xential source down
- **WHEN** the handler generates the decision
- **THEN** generation SHALL fail with "Xential is niet bereikbaar" and no document SHALL be stored
- @e2e exclude the vendor is an external system; asserted in PHPUnit on the adapter
