# external-integration-test-wiring (delta)

@e2e exclude Backend contract-lane wiring with no browser surface; proven by PHPUnit (IntegrationTierTest, PersonRelationReaderTest).

## MODIFIED Requirements

### Requirement: Integration adapters MUST be selected by configuration, defaulting to no external calls

Each integration seam dossiq holds SHALL offer its real adapter alongside the Log/Mock adapter,
selected by an app-config mode key; when the mode is unset or unknown, the Log adapter SHALL be
bound so no external call is ever made unknowingly. Credentials/endpoints SHALL live in
admin-editable config, never in code. Dossiq holds no BRP and no KvK seam (ADR-118): integriq
holds both sources, and dossiq SHALL NOT read `integration.brp.*` or `integration.kvk.*`.

#### Scenario: Fresh install calls nothing external

- **GIVEN** a fresh dossiq install with no integration config
- **WHEN** any flow touches a DSO, DigiD, or e-Depot seam
- **THEN** the Log adapter MUST handle it and no external request MUST leave the instance

#### Scenario: The retired BRP tier key does nothing

- **GIVEN** an instance where `integration.brp.mode` is still set to `test`
- **WHEN** the conflict-of-interest check runs
- **THEN** dossiq SHALL make no BRP request of its own
- **AND** the relation step SHALL follow what integriq's BRP source allows

### Requirement: Each integration MUST have contract tests against its best automatable tier

DSO SHALL be contract-tested against recorded pre-productie fixtures; the e-Depot pipeline's MDTO
output against the Nationaal Archief MDTO XSD offline in CI.

#### Scenario: SIP metadata is schema-valid

- **WHEN** the archival pipeline generates a SIP for a test case
- **THEN** its MDTO XML MUST validate against the published MDTO XSD in CI

#### Scenario: Dossiq carries no KvK contract lane

- **GIVEN** dossiq ships no KvK client (ADR-118)
- **WHEN** PR CI runs dossiq's contract suites
- **THEN** no suite SHALL call or replay the KvK API
- **AND** the KvK contract SHALL be tested where the client lives, in integriq

#### Scenario: Dossiq carries no BRP contract lane

- **GIVEN** dossiq ships no BRP client (ADR-118)
- **WHEN** PR CI runs dossiq's contract suites
- **THEN** no suite SHALL call or replay the BRP API
- **AND** the BRP contract SHALL be tested where the client lives, in integriq
