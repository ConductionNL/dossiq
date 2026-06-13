---
kind: code
depends_on: [tenant-zaaksysteem-saas-10-billing-shillinq]
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

# Proposal: tenant-zaaksysteem-saas-11-suspension-termination

Member 11 of 12 in the **tenant-zaaksysteem-saas** chain (ADR-032). Predecessor: `tenant-zaaksysteem-saas-10-billing-shillinq`. This `kind: code` member completes the tenant lifecycle: suspension/reactivation (access throttled, billing paused, Shillinq notified) and termination with data archival (final invoice, access revoked, schema archived then deleted after retention).

## Why

The lifecycle state machine (member 02) defined the transitions; this member implements their side effects. Suspension handles budget freezes/non-payment; termination handles contract end with AVG-compliant data deletion after a retention period — a legal requirement. Final billing (member 10) is settled before access is revoked.

## What Changes (this member)

1. `TenantService.suspend(tenantId, reason)` / `reactivate(tenantId)`: status transitions, a middleware 403 gate while suspended, no billing events during suspension, Shillinq suspend/reactivate webhooks.
2. `TenantService.terminate(tenantId, reason, retentionYears=1)`: status=terminated, finalise pending billing, Shillinq termination webhook, access revocation.
3. `ArchiveTenantData` job: retain schema for the retention period (cold storage for enterprise per dataResidency), then delete; terminated/suspended access gate.

## Impact

- **Affected**: procest (`TenantService` suspend/reactivate/terminate, `ArchiveTenantData` job, lifecycle middleware gate), shillinq (lifecycle webhooks).
- **Traces to giant tasks**: Task 19 (suspension/reactivation), Task 20 (termination + archival), REQ-008-A/B.
- **Depends on**: member 10 (final-invoice settlement) + member 02 (lifecycle state machine).
