## ADDED Requirements

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
