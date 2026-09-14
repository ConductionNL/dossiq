## ADDED Requirements

### Requirement: An admin inspects a case from its page (REQ-CM-36)

`#CaseDetail` SHALL offer Inspect to administrators only, with Raw data
opening `CnObjectMetadataModal` on the case and Flow runs opening
OpenRegister's runs page filtered to the case.

#### Scenario: Raw data opens the metadata modal
@e2e tests/e2e/admin-inspect.spec.ts

- **GIVEN** you are an admin on a case page
- **WHEN** you press Inspect, then Raw data
- **THEN** the metadata modal SHALL show the case object's raw JSON

#### Scenario: Handlers do not see Inspect
@e2e tests/e2e/admin-inspect.spec.ts

- **GIVEN** you are a handler on a case page
- **WHEN** you open the header actions
- **THEN** Inspect SHALL NOT be offered
