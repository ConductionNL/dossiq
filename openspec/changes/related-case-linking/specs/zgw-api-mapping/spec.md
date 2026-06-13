---
status: proposed
---

# Spec delta: zgw-api-mapping (related-case-linking)

Extends the existing `zgw-api-mapping` capability: the ZRC Zaak resource gains a bidirectional
mapping for `relevanteAndereZaken`, backed by the `case.relatedCases` field whose behaviour is
specified in `related-case-linking`.

## ADDED Requirements

### Requirement: ZRC Zaak resource MUST map relevanteAndereZaken bidirectionally

@e2e exclude ZGW API-contract requirement — proven by the Newman collection tests/newman/relevante-andere-zaken.postman_collection.json (outbound array shape, inbound resolve+guard, unresolvable-URL rejection, empty-array) and ZrcController unit-level mapping; no Playwright UI surface (ZGW is a machine-to-machine API).

The ZGW mapping layer SHALL translate `case.relatedCases` to the ZRC Zaak field `relevanteAndereZaken` as an array of `{url, aardRelatie}` objects (outbound), and SHALL accept `relevanteAndereZaken` on inbound zaak create/update by resolving each `url` to a local case UUID and routing the result through the case-relation guards, per the existing URL-reference translation and error-diagnostic requirements of this capability.

#### Scenario: Outbound zaak includes relevanteAndereZaken

- **GIVEN** a case whose `relatedCases` contains `{caseId: <uuid-B>, aardRelatie: onderwerp}`
- **WHEN** a ZGW consumer retrieves the zaak via `GET /api/zgw/zaken/v1/zaken/{uuid}`
- **THEN** the response MUST contain `relevanteAndereZaken: [{url: <absolute zaak URL for uuid-B>, aardRelatie: "onderwerp"}]`
- **AND** the procest-local `toelichting` MUST NOT appear in the ZGW shape

#### Scenario: Inbound relevanteAndereZaken is resolved and guarded

- **GIVEN** an authenticated ZGW client PATCHes a zaak with `relevanteAndereZaken: [{url: <zaak URL of case B>, aardRelatie: "vervolg"}]`
- **WHEN** the mapping layer processes the request
- **THEN** the URL MUST be resolved to case B's local UUID and the relation stored on both cases per the bidirectional-consistency requirement of `related-case-linking`

#### Scenario: Unresolvable relation URL is rejected with diagnostics

- **WHEN** an inbound zaak write references a `relevanteAndereZaken` URL that does not resolve to a local case
- **THEN** the request MUST be rejected with the capability's standard ZGW validation error shape identifying the offending URL

#### Scenario: Empty relations map to an empty array

- **GIVEN** a case with no peer relations
- **WHEN** the zaak is retrieved via the ZRC endpoint
- **THEN** `relevanteAndereZaken` MUST be present as `[]` (VNG schema compliance), not omitted or null
