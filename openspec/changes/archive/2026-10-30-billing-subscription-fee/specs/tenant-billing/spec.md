# tenant-billing Delta

## ADDED Requirements

### Requirement: The monthly package fee is a billing event

The billing event types SHALL include `subscription_fee`, the monthly fee of a tenant's package, in the service's allowed types and in the `tenantBillingEvent` schema alike.

#### Scenario: Recording the fee
- **GIVEN** a tenant on the Standaard package
- **WHEN** its monthly fee is recorded as a `subscription_fee` event of € 450
- **THEN** the tenant page's billing card SHALL list it beside the case charges
