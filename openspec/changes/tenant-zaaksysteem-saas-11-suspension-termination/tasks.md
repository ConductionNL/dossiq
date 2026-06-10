# Tasks: tenant-zaaksysteem-saas-11-suspension-termination

> **Build status (hydra audit).** The basic TenantService + TenantMiddleware + TenantController shipped via the sibling 'migrate-tenant-to-or-tenant' change (delegates to OR's TenantLifecycleService). This 12-member SaaS chain layers on the full SaaS shape — Tenant/TenantConfiguration/TenantQuota/TenantUser schemas, schema-per-tenant provisioning, JWT tenant-claim auth, mandate validation, onboarding workflow, branding, quota enforcement, shillinq billing, suspension/termination, isolation tests — none of which exist on dev yet. Tasks stay [ ] as genuine forward work.

Member 11 of 12 (code). Depends on member 10. Traces to giant Task 19 + Task 20 + REQ-008.

## 1. Suspension / reactivation

- [ ] Implement `TenantService.suspend(tenantId, reason)` (status=suspended, no billing during suspension)
- [ ] Add lifecycle middleware gate: suspended → 403 "Tenant is suspended" (existing cases visible)
- [ ] Send Shillinq suspend webhook `{tenant_id, status:"suspended", effective_date}`
- [ ] Implement `TenantService.reactivate(tenantId)` (status=active, billing resumes, webhook)

## 2. Termination + archival

- [ ] Implement `TenantService.terminate(tenantId, reason, retentionYears=1)` (status=terminated, terminatedAt)
- [ ] Finalise pending billing (all TenantBillingEvents have invoiceRef) before revoking access
- [ ] Send Shillinq termination webhook with final invoice; revoke API access (terminated → 403)
- [ ] Implement `ArchiveTenantData` job (retain then delete; enterprise → cold storage per dataResidency)
- [ ] Write an immutable deletion-confirmation log entry on schema deletion

## 3. Tests

- [ ] Integration test: suspend → 403 on creation; existing visible; reactivate restores
- [ ] Integration test: terminate → status, billing settled, access revoked (403)
- [ ] Integration test: archival retains then deletes schema (mock destination) + deletion log
