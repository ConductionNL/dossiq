# Tasks: tenant-zaaksysteem-saas-11-suspension-termination

Member 11 of 12 (code). Depends on member 10. Traces to giant Task 19 + Task 20 + REQ-008.

## 1. Suspension / reactivation

- [~] Implement `TenantService.suspend(tenantId, reason)` (status=suspended, no billing during suspension) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add lifecycle middleware gate: suspended → 403 "Tenant is suspended" (existing cases visible) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Send Shillinq suspend webhook `{tenant_id, status:"suspended", effective_date}` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `TenantService.reactivate(tenantId)` (status=active, billing resumes, webhook) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Termination + archival

- [~] Implement `TenantService.terminate(tenantId, reason, retentionYears=1)` (status=terminated, terminatedAt) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Finalise pending billing (all TenantBillingEvents have invoiceRef) before revoking access — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Send Shillinq termination webhook with final invoice; revoke API access (terminated → 403) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `ArchiveTenantData` job (retain then delete; enterprise → cold storage per dataResidency) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Write an immutable deletion-confirmation log entry on schema deletion — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Tests

- [~] Integration test: suspend → 403 on creation; existing visible; reactivate restores — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: terminate → status, billing settled, access revoked (403) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: archival retains then deletes schema (mock destination) + deletion log — deferred to downstream cycle / fleet-wide adoption (handoff)
