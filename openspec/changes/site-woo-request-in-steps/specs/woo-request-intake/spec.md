## ADDED Requirements

### Requirement: The Woo request keeps what kind of documents and which requester (REQ-SWS-010)
`WooRequestForm` MUST accept the optional fields `documentSoorten` (a list of `besluiten`,
`rapporten`, `correspondentie`, `alles`), `toelichting`, `verzoekerNaam`, `verzoekerEmail` and
`verzoekerType` (`burger`, `journalist`, `organisatie`), and MUST keep them on `case.wooRequest`.
The requester fields MUST also fill the Woo case type's intake properties of the same name. A
value outside its list, or an address that is not an e-mail address, MUST be refused with
`invalid`. `PortalWooRequestController` MUST forward the new fields. The required fields of
REQ-WRI-002 MUST stay as they are, so pipelinq's conversion is unaffected.

#### Scenario: A request with document kinds
- **GIVEN** a portal request with `documentSoorten` `["besluiten", "correspondentie"]`
- **WHEN** `start()` runs
- **THEN** `case.wooRequest.documentSoorten` MUST hold both values

#### Scenario: An unknown document kind
- **GIVEN** a request with `documentSoorten` `["geheim"]`
- **WHEN** `start()` runs
- **THEN** it MUST refuse with `invalid` and write no case

#### Scenario: Pipelinq without the new fields
- **GIVEN** a pipelinq conversion with only `onderwerp`
- **WHEN** `start()` runs
- **THEN** the case MUST be opened as today

### Requirement: The intake answers with the case number and deadline (REQ-SWS-011)
`start()` MUST answer `{caseId, caseUrl, identifier, deadline}`, reading `identifier` and
`deadline` back from the written case. When the case carries no deadline yet, the answer MUST
leave `deadline` out rather than send an empty value.

#### Scenario: The deadline is computed
- **GIVEN** a request written on 2 October 2026 for the seeded Woo type
- **WHEN** the route answers
- **THEN** the body MUST carry the case's `identifier` and a `deadline` 28 days after its start
