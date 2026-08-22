# kvk-register Specification

**Status:** done
**OpenSpec changes**: [brp-kvk-register-sets](../../changes/archive/2026-07-06-brp-kvk-register-sets/) _(archived 2026-07-06)_
**Scope:** KvK company register set (schema + fictitious seed data) in OpenRegister
**Depends on:** `external-integrations-test-environments` (live KvK adapter + network-gated contract lane — owns the real-API side; seed rows here are its contract fixtures)
**Standards:** KvK Zoeken API (field naming), GEMMA Zaakafhandel (initiator betrokkene), ZGW ZRC Rol `niet_natuurlijk_persoon`, Schema.org `schema:Organization`
**Feature tier:** MVP

## Requirements

### Requirement: KvK company register schema exists in OpenRegister

The dossiq register configuration SHALL provide a `kvkCompany` schema (ADR-037 fragment
`lib/Settings/register.d/25-brp-kvk.json`) following KvK Zoeken API field naming: `kvkNummer`
(string, 8 digits), `handelsnaam`, `rechtsvorm`, and `adres` (straatnaam, huisnummer, postcode,
plaats). The schema SHALL carry `x-schema-org: schema:Organization` and map to the ZGW Rol
betrokkene `niet_natuurlijk_persoon`. The schema is a simplified test model named after the API
conventions — it SHALL NOT claim or require a live KvK connection.

#### Scenario: Schema imports via the Repair step

@e2e exclude Repair-step/OR-import behaviour with no browser surface; schema shape and KvK Zoeken naming proven by PHPUnit (BrpKvkRegisterSetsTest); live import happens in the deploy lane.

- **GIVEN** a dossiq install with OpenRegister active
- **WHEN** the register configuration is (re-)imported via the Repair step
- **THEN** the `kvkCompany` schema MUST exist in the dossiq register with the KvK Zoeken field names
- **AND** re-running the import MUST leave existing rows valid (idempotent, additive)

### Requirement: Seed companies are the published fictitious test companies

The register set SHALL seed 10 fictitious `kvkCompany` rows via the fragment's
`components.objects`, including at minimum the KvK-published fictitious test companies KVK
**69599084, 68750110, 69599068, 55344526** (the pinned fixtures of the KvK contract lane owned by
`external-integrations-test-environments`), padded to 10 from the same published fictitious set —
never invented KvK numbers. Each row's description SHALL identify it as official fictitious test
data.

#### Scenario: Pinned fixture companies are seeded

@e2e exclude Import-time data presence (API-layer): the four pinned numbers + 10-row count are pinned by PHPUnit (BrpKvkRegisterSetsTest::testSeedCompaniesIncludePinnedFixtures); the UI path over the same data is covered by the company-search e2e test in brp-kvk-initiator.spec.ts.

- **WHEN** the register configuration is imported on a fresh instance
- **THEN** 10 `kvkCompany` rows MUST exist and be searchable through the OpenRegister objects API
- **AND** the rows MUST include KVK numbers 69599084, 68750110, 69599068, and 55344526

#### Scenario: Seed companies resolve against the KvK test API

@e2e exclude Network-gated fixture-parity check against api.kvk.nl/test — owned by the external-integrations-test-environments KvK contract lane (contract tests, not Playwright UI). Seeds were fetched verbatim from the test Zoeken API at pin time (2026-07-06, incl. handelsnamen/adressen).

- **GIVEN** the network-gated KvK contract lane configured against `api.kvk.nl/test`
- **WHEN** a seeded company's KvK number is queried there
- **THEN** the test API MUST return the same fictitious company (same kvkNummer and handelsnaam)
