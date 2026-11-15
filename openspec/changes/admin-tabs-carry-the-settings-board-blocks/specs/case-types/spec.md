# case-types Delta: admin-tabs-carry-the-settings-board-blocks

## ADDED Requirements

### Requirement: A case type a package placed reads as shipped

A case type written by a case definition package SHALL carry `shipped: true` and `package` set to the package id, so the case types list (board DqZaaktypen) can offer the quick filter Shipped and each row can read Shipped or Own. A case type the organisation makes, and every copy of a shipped case type, SHALL carry `shipped: false` and an empty `package`. A package that adopts a case type the organisation already had SHALL NOT mark it shipped.

#### Scenario: A package places a case type
@e2e exclude a package install has no UI step to drive; proven by tests/Unit/Service/CaseDefinition/PackageWriterGoldenTest.php
- **GIVEN** the package `jeugdwet` ships the case type "Melding"
- **WHEN** the package is installed
- **THEN** "Melding" is stored with `shipped: true` and `package: "jeugdwet"`

#### Scenario: A copy is the organisation's own
@e2e exclude covered by tests/Unit/Service/CaseType/DerivedCaseTypePayloadTest.php
- **GIVEN** a shipped case type
- **WHEN** an administrator copies it
- **THEN** the copy has `shipped: false` and an empty `package`
