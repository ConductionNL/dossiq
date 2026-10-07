## ADDED Requirements

### Requirement: An admin inspects a case from its page (REQ-CM-36)

`#CaseDetail` SHALL offer Inspect raw data and Inspect flow runs to
administrators only. Inspect raw data SHALL show the case exactly as Open
Register stored it, and Inspect flow runs SHALL open Open Register's runs page
filtered to the case.

Both are affordances and not controls: Open Register answers the read either
way, and it is the one that refuses.

#### Scenario: Raw data shows the stored case
@e2e tests/e2e/admin-inspect.spec.ts

- **GIVEN** you are an admin on a case page
- **WHEN** you press Inspect raw data
- **THEN** a dialog SHALL show the case object as Open Register stored it

#### Scenario: Handlers do not see Inspect
@e2e tests/e2e/admin-inspect.spec.ts

- **GIVEN** you are a handler on a case page
- **WHEN** you open the header actions
- **THEN** neither Inspect entry SHALL be offered
