## ADDED Requirements

### Requirement: A tenant past its hard quota cannot create more (REQ-TQ-ENF-01)

Creating a case, uploading a document and adding a user SHALL consume the tenant's matching quota. When the write would exceed the hard limit of a quota enforced as `block` or `throttle` it SHALL be refused before the save, through OpenRegister's hook refusal (HTTP 422), naming the quota, the limit and the tier; a quota enforced as `warn` lets the write through. When a write crosses the soft limit the tenant's administrators SHALL be notified once per period.

#### Scenario: The 501st case is refused

- **GIVEN** a tenant whose cases quota is 500 per month, enforced as `block`, with 500 created
- **WHEN** a handler creates another case
- **THEN** the create SHALL be refused naming "500 zaken per maand"
- @e2e tests/e2e/tenant-quota.spec.ts

#### Scenario: Crossing the soft limit notifies once

- **GIVEN** a soft limit of 400 cases
- **WHEN** the 400th and 401st cases are created
- **THEN** the tenant administrators SHALL get one notification
- @e2e exclude notification delivery is asserted on the listener in PHPUnit

### Requirement: An administrator sees and runs a tenant's monthly billing (REQ-TQ-BILL-01)

The tenant detail page SHALL show the chosen month's usage lines and amount from the billing summary, and an administrator SHALL be able to run the month's billing, which hands the invoice to the finance app once per tenant and month.

#### Scenario: Running billing twice makes one invoice

- **GIVEN** the billing summary for tenant "Waterland" for October
- **WHEN** an administrator runs billing twice
- **THEN** one invoice SHALL be handed to the finance app
- @e2e tests/e2e/tenant-quota.spec.ts
