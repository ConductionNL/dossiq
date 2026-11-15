# admin-data-access Delta: admin-data-access

**Status**: draft
**Scope**: the OpenRegister authorization of avgIncident, supplierUser, tenantUser, tenantMandate and tenantBillingEvent.

## ADDED Requirements

### Requirement: The records behind admin-only pages MUST carry their own authorization

avgIncident, supplierUser, tenantUser, tenantMandate and tenantBillingEvent SHALL each declare a schema-level authorization block, so the register block that grants every member of staff does not reach them. No verb SHALL be granted to `public` or `authenticated`, and update and delete SHALL be limited to admin and beheerders, with the background service account where a job writes.

#### Scenario: A member of staff reads supplier accounts

- **GIVEN** a user in `dossiq-medewerkers` who is not in `admin` or `beheerders`
- **WHEN** they request the supplierUser objects from OpenRegister
- **THEN** OpenRegister refuses the read

#### Scenario: A member of staff reports a data breach

- **GIVEN** a user in `dossiq-medewerkers`
- **WHEN** they create an avgIncident
- **THEN** OpenRegister accepts it
- **AND** that user cannot read the list of breaches

#### Scenario: The mandate check reads as the signed-in user

- **GIVEN** a member of staff working in a tenant
- **WHEN** MandateValidationMiddleware reads their tenantUser and tenantMandate rows
- **THEN** the read is allowed
- **AND** the same user cannot change those rows
