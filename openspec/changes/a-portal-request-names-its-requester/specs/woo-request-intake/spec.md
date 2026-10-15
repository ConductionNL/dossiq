# woo-request-intake Delta: a-portal-request-names-its-requester

**Status**: draft
**Scope**: the requester of a Woo request filed through the intake, and how the case page shows it.

## ADDED Requirements

### Requirement: A case filed for a resident names who asked (REQ-WRI-006)

A Woo request that carries a requester name SHALL write that name as the case's `initiatorDisplayName`, unless the writer set one. The case page's Requester SHALL show `initiatorDisplayName`, so a request filed by a resident without a register row reads with their name, and a case naming nobody reads as empty.

#### Scenario: A portal request shows its requester
@e2e exclude filing a request mutates a shared instance; covered by WooRequestIntakeTest and formatters.spec.js
- **GIVEN** a resident who files a Woo request on the portal as "Sanne de Vries"
- **WHEN** a handler opens the case
- **THEN** Requester reads "Sanne de Vries"

#### Scenario: A request without a name names nobody
@e2e exclude covered by WooRequestIntakeTest
- **GIVEN** a request converted from a phone call without a requester name
- **WHEN** the case is written
- **THEN** it carries no `initiatorDisplayName`
