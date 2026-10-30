## ADDED Requirements

### Requirement: A supplier reads its own tenders, contracts and messages through portal collections (REQ-SUP-COL-01)

A signed-in supplier SHALL see on the portal the tenders, contracts, invoices and messages whose `supplierRef` is their own, read through dossiq's portal collections, and SHALL be able to reply to a message. Dossiq SHALL NOT need a contract controller for this; another supplier's rows SHALL never be returned.

#### Scenario: A supplier sees only its own contracts

- **GIVEN** suppliers "Bouwbedrijf Jansen" and "Groenwerk BV" each with one contract
- **WHEN** Bouwbedrijf Jansen signs in on the portal and opens contracts
- **THEN** exactly its own contract SHALL be listed
- @e2e exclude the supplier pages are portaliq's; asserted in portaliq's journey and on the scope in PHPUnit (`tests/Unit/Portal/PortalContributionProviderTest.php`)

#### Scenario: A supplier replies to a message

- **GIVEN** a message from the buyer in the supplier's inbox
- **WHEN** the supplier replies
- **THEN** the reply SHALL be stored as a `supplierMessage` from the supplier and shown to the buyer on the tender case
- @e2e exclude the reply is made on portaliq's page; asserted on the contribution and the listener in PHPUnit
