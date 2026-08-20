# external-integration-test-wiring Specification

**Status:** done
**Scope:** procest external-integration seams (BRP, KvK, DSO, DigiD/eHerkenning, e-Depot)
**Depends on:** `pluggable-integration-registry` (seam mechanics, sequencing per DC02); `migrate-archival-to-or` (e-Depot seam moves to OR transports); `brp-kvk-register-sets` (register-side seed data — extended, not duplicated)
**OpenSpec changes**: [external-integrations-test-environments](../../changes/archive/2026-07-06-external-integrations-test-environments/) _(archived 2026-07-06)_

## Purpose

@e2e exclude Backend integration-wiring capability (config-tier adapter selection, HTTP adapters, contract lanes, features-overlay promotion) — proven by PHPUnit (IntegrationTierTest, BrpKvkContractTest) and the offline/recorded contract fixtures; the only UI angle (DigiD simulator login label) belongs to the zaakportaal-mijngemeente beta surface which has no procest login page yet. Mirrors the stuf-zkn-outbound (Newman/PHPUnit, no Playwright) precedent.

Procest's external integrations run against real, named test environments behind configuration,
with contract tests per automatable tier and explicit promotion criteria — ending the state where
every integration terminates in a permanently-bound Mock/Log adapter.

## Requirements

### Requirement: Integration adapters MUST be selected by configuration, defaulting to no external calls

Each integration seam SHALL offer its real adapter alongside the Log/Mock adapter, selected by an
app-config mode key; when the mode is unset or unknown, the Log adapter SHALL be bound so no
external call is ever made unknowingly. Credentials/endpoints SHALL live in admin-editable config,
never in code.

#### Scenario: Fresh install calls nothing external

- **GIVEN** a fresh procest install with no integration config
- **WHEN** any flow touches a BRP, KvK, DSO, DigiD, or e-Depot seam
- **THEN** the Log adapter MUST handle it and no external request MUST leave the instance

#### Scenario: Admin flips BRP to the test tier

- **GIVEN** an admin sets `integration.brp.mode=test` with base URL and API key
- **WHEN** initiator search queries a person
- **THEN** the request MUST hit the configured Haal Centraal proefomgeving endpoint with the X-API-KEY header

### Requirement: Each integration MUST have contract tests against its best automatable tier

BRP SHALL be contract-tested in CI against the official `ghcr.io/brp-api/personen-mock` docker
mock; KvK against `api.kvk.nl/test` (public test key, fixed fictitious-company fixtures) in a
network-gated lane; DSO against recorded pre-productie fixtures; the e-Depot pipeline's MDTO
output against the Nationaal Archief MDTO XSD offline in CI. Fixture datasets SHALL be shared with
the `brp-kvk-register-sets` seed data so demo data and contract fixtures are the same objects.

#### Scenario: BRP contract lane runs offline

- **WHEN** PR CI runs the BRP contract suite
- **THEN** it MUST run against the personen-mock service container without internet access
- **AND** a contract break (schema/field drift) MUST fail the lane

#### Scenario: SIP metadata is schema-valid

- **WHEN** the archival pipeline generates a SIP for a test case
- **THEN** its MDTO XML MUST validate against the published MDTO XSD in CI

### Requirement: Authentication integrations MUST NOT claim what only preproduction can prove

The DigiD/eHerkenning seams SHALL gain (i) a simulator adapter (BSN-entry login modelled on the
maykinmedia mock pattern) usable for demos and e2e journeys, and (ii) a real SAML adapter whose
artifact flow is validated exclusively against the Logius preproductieomgeving. The simulator SHALL
be visibly labelled as simulation and SHALL never be promotable past `beta`.

#### Scenario: Simulator login is explicit about itself

- **WHEN** a user authenticates through the DigiD simulator tier
- **THEN** the login UI MUST state it is a simulation
- **AND** the resulting session MUST be marked as simulator-authenticated

#### Scenario: SAML validation evidence gates stable

- **WHEN** the DigiD feature is proposed for `stable`
- **THEN** evidence of a successful artifact flow against Logius preproductie MUST exist

### Requirement: Feature maturity MUST follow the promotion criteria

Per integration, the features overlay SHALL grade `mock → beta` only with a green contract lane on
the best automatable tier, and `beta → stable` only with end-to-end validation against the official
test/pre-prod environment plus an operator runbook in `docs/admin/`. Downgrades apply the same
gates in reverse.

#### Scenario: Promotion PR carries evidence

- **WHEN** an integration feature's overlay status is raised
- **THEN** the change MUST link the required evidence for the target tier
- **AND** absent evidence MUST block the promotion
