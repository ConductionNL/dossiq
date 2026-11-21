# Proposal: billing-subscription-fee

## Why

DqTenant's billing card lists the monthly fee of the package ("Maandbedrag pakket Standaard, € 450,00") beside case charges and refunds. A billing event can only be one of the declared event types, and none of them is a recurring package fee, so the fee could neither be recorded nor shown.

## What changes

- A new billing event type, `subscription_fee`, for the monthly package fee: in `TenantBillingService::ALLOWED_EVENT_TYPES` and in the `tenantBillingEvent` schema enum (both registers).
- The schema version moves to 1.1.0.
- Emitting it monthly is not part of this change. Today the fee is recorded by whoever bills (or by the example set).
