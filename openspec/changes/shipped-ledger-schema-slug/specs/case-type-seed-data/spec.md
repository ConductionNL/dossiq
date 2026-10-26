# case-type-seed-data Delta: shipped-ledger-schema-slug

## ADDED Requirements

### Requirement: The shipped configuration finds rows a package recorded by schema id

The provenance ledger SHALL record a row's schema by slug, mapping a configured schema id to its slug, and reading the ledger for a slug SHALL also find rows recorded under that slug's configured schema id.

#### Scenario: VTH and Woo installed
- GIVEN the ledger holds case type rows under targetSchema 165, the configured case type schema
- WHEN the shipped configuration lists case types
- THEN it lists them with their set and version (vth 1.4, woo-verzoek 2.1)
