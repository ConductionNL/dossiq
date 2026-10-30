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

### Requirement: The quota card and the refusal read the same limits (REQ-TQ-ENF-02)

The user limit SHALL be OpenRegister's organisation `userQuota` and the storage limit its `storageQuota`, with no second copy on the `tenantQuota` row, so the organisation page's quota card and the refusal name the same number. Storage SHALL be counted in megabytes on the `storage_mb` row, each uploaded document adding its size rounded up to whole megabytes. `cases_per_month` SHALL stay a dossiq monthly quota, shown on its own row of the quota card. Users and storage are standing totals and SHALL NOT be reset by the monthly reset.

#### Scenario: The user limit set on the organisation refuses the next user

- **GIVEN** an organisation whose `userQuota` is 5, with an `active_users` quota enforced as `block` and 5 users counted
- **WHEN** an administrator adds a sixth user to the tenant
- **THEN** the create SHALL be refused naming "5 users", the number the quota card shows
- @e2e exclude the limit lookup is asserted on the quota service in PHPUnit

#### Scenario: An upload past the storage limit is refused

- **GIVEN** a tenant whose storage limit is 10 MB, enforced as `block`, with 9 MB counted
- **WHEN** a handler uploads a 2 MB document to a case
- **THEN** the upload SHALL be refused naming "10 MB" and nothing SHALL be stored
- @e2e exclude the upload handler is asserted in PHPUnit

#### Scenario: The monthly reset leaves storage and users alone

- **GIVEN** a `storage_mb` row at 900 and a `cases_per_month` row at 40, both past their reset date
- **WHEN** the monthly reset runs
- **THEN** the cases row SHALL read 0 and the storage row SHALL still read 900
- @e2e exclude a background job, asserted in PHPUnit

#### Scenario: The quota card shows this month's cases on its own row

- **GIVEN** a tenant with 40 cases counted this month against a limit of 100
- **WHEN** an administrator opens the organisation page
- **THEN** the quota card SHALL show a "Cases this month" row reading 40 of 100 beside the organisation's own rows
- @e2e tests/e2e/tenant-quota.spec.ts

### Requirement: An administrator sees and runs a tenant's monthly billing (REQ-TQ-BILL-01)

The tenant detail page SHALL show the chosen month's usage lines and amount from the billing summary, and an administrator SHALL be able to run the month's billing, which hands the invoice to the finance app once per tenant and month.

#### Scenario: Running billing twice makes one invoice

- **GIVEN** the billing summary for tenant "Waterland" for October
- **WHEN** an administrator runs billing twice
- **THEN** one invoice SHALL be handed to the finance app
- @e2e tests/e2e/tenant-quota.spec.ts
