## ADDED Requirements

### Requirement: A tenant past its hard quota cannot create more (REQ-TQ-ENF-01)

Creating a case, uploading a document and adding a user SHALL consume the tenant's matching quota. When the write would exceed the hard limit it SHALL be refused with HTTP 429 naming the quota, the limit and the tier; when it crosses the soft limit the tenant's administrators SHALL be notified once per period.

#### Scenario: The 501st case is refused

- **GIVEN** a tenant on a tier with a hard limit of 500 cases this month and 500 created
- **WHEN** a handler creates another case
- **THEN** the create SHALL be refused naming "500 zaken per maand"
- @e2e tests/e2e/tenant-quota.spec.ts

#### Scenario: Crossing the soft limit notifies once

- **GIVEN** a soft limit of 400 cases
- **WHEN** the 400th and 401st cases are created
- **THEN** the tenant administrators SHALL get one notification
- @e2e exclude notification delivery is asserted on the listener in PHPUnit

### Requirement: An administrator sees and runs a tenant's monthly billing (REQ-TQ-BILL-01)

The tenant detail page SHALL list the tenant's billing events with their amounts, as board DqTenant draws the Facturatie card, and an administrator SHALL be able to invoice last month from the page's More menu. A run hands the invoice to the finance app once per tenant and month; a run with nothing left to invoice says so instead of reporting success.

#### Scenario: Running billing twice makes one invoice

- **GIVEN** the billing summary for tenant "Waterland" for October
- **WHEN** an administrator runs billing twice
- **THEN** one invoice SHALL be handed to the finance app
- @e2e tests/e2e/tenant-quota.spec.ts
