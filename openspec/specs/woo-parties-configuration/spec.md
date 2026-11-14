# woo-parties-configuration Specification

## Purpose
What dossiq does for woo parties configuration. The requirements were first written by the change `woo-parties-configuration` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-woo-parties-configuration/`) and cover: A Woo case names its parties' roles and its contacts are parties.

## Requirements

### Requirement: A Woo case names its parties' roles and its contacts are parties

The Woo request case type SHALL ship the role types Verzoeker, Gemachtigde, Belanghebbende, Adviseur and Melder. The `brpPerson` schema SHALL declare itself a party of kind person, and the `kvkCompany` schema a party of kind organisation. Each SHALL carry an `indicators` list that OpenRegister's party model reads.

#### Scenario: A representative on a Woo case
- **GIVEN** the configuration is imported
- **WHEN** a handler links a BRP person to a Woo case as Gemachtigde with a period
- **THEN** the party model SHALL accept the link, and the case's Roles section SHALL show the person under that role with the period
