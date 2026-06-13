---
kind: code
depends_on: [tenant-zaaksysteem-saas-09-quotas-enforcement]
chain:
  - tenant-zaaksysteem-saas-01-schemas-and-seed
  - tenant-zaaksysteem-saas-02-tenant-crud-lifecycle
  - tenant-zaaksysteem-saas-03-schema-provisioning
  - tenant-zaaksysteem-saas-04-tenant-context-isolation
  - tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim
  - tenant-zaaksysteem-saas-06-mandate-validation
  - tenant-zaaksysteem-saas-07-onboarding-workflow
  - tenant-zaaksysteem-saas-08-configuration-branding
  - tenant-zaaksysteem-saas-09-quotas-enforcement
  - tenant-zaaksysteem-saas-10-billing-shillinq
  - tenant-zaaksysteem-saas-11-suspension-termination
  - tenant-zaaksysteem-saas-12-isolation-tests-compliance
---

# Proposal: tenant-zaaksysteem-saas-10-billing-shillinq

Member 10 of 12 in the **tenant-zaaksysteem-saas** chain (ADR-032). Predecessor: `tenant-zaaksysteem-saas-09-quotas-enforcement`. This `kind: code` member builds the billing pipeline: event emission on the case lifecycle, the daily Shillinq export job, and the tenant billing dashboard.

## Why

The SaaS revenue model is usage-based (cases/month, overages); billing events must be captured immutably and exported to Shillinq for invoicing. The quota_exceeded events from member 09 feed this pipeline, and the dashboard gives tenants billing transparency (mitigating disputes — a named risk).

## What Changes (this member)

1. `TenantBillingService` (emitEvent, getMonthBilling) emits `case_created`/`case_closed`/`user_activated`/`case_refund` events on the case lifecycle as `TenantBillingEvent` rows (invoiceRef NULL).
2. `ExportBillingToShillinq` daily job + `ShillinqIntegrationService`: groups pending events by tenant-month, calls the Shillinq API, sets invoiceRef on success, retries 3× with backoff and defers on failure.
3. `TenantBillingController` + `BillingDashboardService`: current-month summary, YTD breakdown, forecast, invoice history, quota status.

## Impact

- **Affected**: procest (`TenantBillingService`, `ShillinqIntegrationService`, `ExportBillingToShillinq` job, `TenantBillingController`, `BillingDashboardService`, billing dashboard Vue), shillinq (invoice API).
- **Traces to giant tasks**: Task 16 (billing event storage + service), Task 17 (Shillinq export job), Task 18 (billing dashboard), REQ-007-A/B/C.
- **Depends on**: member 09 (quota_exceeded events) + member 01 (`TenantBillingEvent` schema).
