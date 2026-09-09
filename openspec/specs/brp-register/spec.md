# brp-register Specification

**Status:** done
**OpenSpec changes**: [brp-kvk-register-sets](../../changes/archive/2026-07-06-brp-kvk-register-sets/) _(archived 2026-07-06)_
**Scope:** BRP person register set (schema + fictitious seed data) in OpenRegister
**Depends on:** `external-integrations-test-environments` (live BRP adapter + contract lane — owns the real-API side; seed rows here are its contract fixtures)
**Standards:** Haal Centraal BRP Personen bevragen (field naming), GEMMA Zaakafhandel (initiator betrokkene), ZGW ZRC Rol `natuurlijk_persoon`, Schema.org `schema:Person`
**Feature tier:** MVP

## Purpose

The BRP person register set in OpenRegister: the `brpPerson` schema and its
fictitious seed rows, named after the Haal Centraal BRP Personen bevragen API so
the live adapter and the seed data describe the same person the same way.

## Requirements

### Requirement: BRP person register schema exists in OpenRegister

The dossiq register configuration SHALL provide a `brpPerson` schema (ADR-037 fragment
`lib/Settings/register.d/25-brp-kvk.json`) following Haal Centraal BRP Personen bevragen field
naming: `burgerservicenummer` (string, `format: bsn` — validated by OpenRegister's registered BSN
format, no dossiq-side duplicate per ADR-011), `naam` (geslachtsnaam, voornamen, voorvoegsel),
`geboorte` (datum), and `verblijfplaats` (straat, huisnummer, postcode, woonplaats). The schema
SHALL carry `x-schema-org: schema:Person` and map to the ZGW Rol betrokkene `natuurlijk_persoon`.
The schema is a simplified test model named after the API conventions — it SHALL NOT claim or
require a live BRP connection.

#### Scenario: Schema imports via the Repair step

@e2e exclude Repair-step/OR-import behaviour with no browser surface; schema shape, Haal Centraal naming, and additive/idempotency guards proven by PHPUnit (BrpKvkRegisterSetsTest); live import happens in the deploy lane.

- **GIVEN** a dossiq install with OpenRegister active
- **WHEN** the register configuration is (re-)imported via the Repair step
- **THEN** the `brpPerson` schema MUST exist in the dossiq register with the Haal Centraal field names
- **AND** re-running the import MUST leave existing rows valid (idempotent, additive)

#### Scenario: BSN validation is enforced by OpenRegister

@e2e exclude OR's validation engine (registered bsn format, ValidateObject.php) — enforced server-side in OpenRegister, e2e-tested there; dossiq proves every shipped seed passes the 11-proef via PHPUnit so the import can never trip it.

- **WHEN** a `brpPerson` object is saved with a `burgerservicenummer` that fails the 11-proef
- **THEN** OpenRegister's `bsn` format validation MUST reject the object

### Requirement: Seed persons are the official mock personas

The register set SHALL seed 10 fictitious `brpPerson` rows via the fragment's `components.objects`.
The rows SHALL be taken from the official BRP `personen-mock` `test-data.json` personas (pinned at
implementation time from the mock's current data set, never invented) so that demo data and the
BRP contract-lane fixtures owned by `external-integrations-test-environments` are the same objects.
Each row's description SHALL identify it as official fictitious test data.

#### Scenario: Seed personas resolve in both worlds

@e2e exclude Network-gated fixture-parity check against the personen-mock container — owned by the external-integrations-test-environments BRP contract lane (Newman/contract tests, not Playwright UI). Seeds were extracted verbatim from the mock's test-data.json at pin time (2026-07-06).

- **GIVEN** the seeded register and the `personen-mock` container used by the BRP contract lane
- **WHEN** a seeded person's BSN is queried against the mock
- **THEN** the mock MUST return the same persona (same BSN, name, and birthdate)

#### Scenario: Seed data present after import

@e2e exclude Import-time data presence (API-layer, not UI) — verified through the OR objects API in the deploy lane; the UI path over the same data is covered by the person-search e2e test in brp-kvk-initiator.spec.ts.

- **WHEN** the register configuration is imported on a fresh instance
- **THEN** 10 `brpPerson` rows MUST exist and be searchable through the OpenRegister objects API

### Requirement: A BRP person carries the secrecy indication (REQ-BRP-003)

You can tell which persons asked for their data to be protected. The
`brpPerson` schema SHALL carry `indicatieGeheim` (boolean, default false),
mapped from Haal Centraal `geheimhoudingPersoonsgegevens`, where any value
other than 0 is true. `brpPerson` SHALL set `logReads: true`, so every read of
a person row is logged by OpenRegister. The seed SHALL flag one of the ten
personas, taken from a personen-mock row that carries the indication, never
invented. The property is additive; existing rows stay valid without it.

#### Scenario: The schema carries the flag after import

@e2e exclude Repair-step import with no browser surface: PHPUnit (BrpKvkRegisterSetsTest::testBrpPersonCarriesIndicatieGeheim) asserts the property, its default and logReads on the fragment; the masked card it drives is asserted in tests/e2e/case-requester.spec.ts under initiator-display.

- **WHEN** the register configuration is imported
- **THEN** `brpPerson` MUST have `indicatieGeheim` as an optional boolean defaulting to false
- **AND** `brpPerson` MUST set `logReads: true`
- **AND** rows without the property MUST remain valid

#### Scenario: One seeded persona is protected

@e2e exclude Seed-data presence at the API layer: PHPUnit (BrpKvkRegisterSetsTest::testExactlyOneSeedPersonaIsProtected) counts the flagged rows in the fragment; the rendered effect is asserted in tests/e2e/case-requester.spec.ts.

- **WHEN** the register configuration is imported on a fresh instance
- **THEN** exactly one of the ten `brpPerson` rows MUST carry `indicatieGeheim: true`
- **AND** that row's BSN MUST match a personen-mock persona with `geheimhoudingPersoonsgegevens` set

#### Scenario: The live adapter maps the indication

@e2e exclude Adapter mapping over a mocked HTTP client: PHPUnit (HaalCentraalBrpAdapterTest::testGeheimhoudingMapsToIndicatieGeheim) covers 0, 1 and absent; no browser surface.

- **GIVEN** a Haal Centraal response with `geheimhoudingPersoonsgegevens: 1`
- **WHEN** `HaalCentraalBrpAdapter` maps the person
- **THEN** the mapped row MUST carry `indicatieGeheim: true`
- **AND** a response with 0 or without the field MUST map to false
