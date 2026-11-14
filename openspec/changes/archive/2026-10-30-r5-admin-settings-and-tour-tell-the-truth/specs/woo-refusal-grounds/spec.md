# woo-refusal-grounds Delta: r5-admin-settings-and-tour-tell-the-truth

## ADDED Requirements

### Requirement: The refusal grounds list has a title and labelled columns

The Woo refusal grounds page SHALL show its title, and every column SHALL
carry a label: Code, Ground, Broader ground, Kind, Citable, Status.

#### Scenario: An admin opens the list
<!-- @e2e exclude Manifest contract; proven by tests/vitest/tourTellsTheTruth.spec.js (manifest block) and live on :8099. -->
- **WHEN** the admin opens Settings, Woo refusal grounds
- **THEN** the page reads "Woo refusal grounds" at the top
- **AND** no column header is empty
