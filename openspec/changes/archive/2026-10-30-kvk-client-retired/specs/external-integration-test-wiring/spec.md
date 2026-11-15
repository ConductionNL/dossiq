# external-integration-test-wiring (delta)

@e2e exclude Backend contract-lane wiring with no browser surface; proven by PHPUnit (BrpKvkContractTest, IntegrationTierTest).

## MODIFIED Requirements

### Requirement: Each integration MUST have contract tests against its best automatable tier

BRP SHALL be contract-tested in CI against the official `ghcr.io/brp-api/personen-mock` docker
mock; DSO against recorded pre-productie fixtures; the e-Depot pipeline's MDTO
output against the Nationaal Archief MDTO XSD offline in CI. Fixture datasets SHALL be shared with
the `brp-kvk-register-sets` seed data so demo data and contract fixtures are the same objects.

#### Scenario: BRP contract lane runs offline

- **WHEN** PR CI runs the BRP contract suite
- **THEN** it MUST run against the personen-mock service container without internet access
- **AND** a contract break (schema/field drift) MUST fail the lane

#### Scenario: SIP metadata is schema-valid

- **WHEN** the archival pipeline generates a SIP for a test case
- **THEN** its MDTO XML MUST validate against the published MDTO XSD in CI

#### Scenario: Dossiq carries no KvK contract lane

- **GIVEN** dossiq ships no KvK client (ADR-118)
- **WHEN** PR CI runs dossiq's contract suites
- **THEN** no suite SHALL call or replay the KvK API
- **AND** the KvK contract SHALL be tested where the client lives, in integriq
