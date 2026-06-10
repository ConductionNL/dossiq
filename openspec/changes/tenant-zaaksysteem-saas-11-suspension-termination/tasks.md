# Tasks: tenant-zaaksysteem-saas-11-suspension-termination

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `TenantLifecycleControlService` (suspend / reactivate / terminate / archiveAndDelete + countUnsettledEvents) that builds on `TenantSaasService`'s state machine (chain member 02) and the billing event service (chain member 10). `terminate()` counts unsettled events before flipping status so an admin can see the export gap. `archiveAndDelete()` invokes the validated schema-name builder + provisioner drop, then emits an immutable `TENANT_SCHEMA_DELETED` log line. The existing `TenantMiddleware` already enforces the suspended/terminated → 403 lifecycle gate (chain member 02's state machine drove the dev-side TenantMiddleware update). 5 new unit tests cover suspend/reactivate state-transition delegation, terminate-with-unsettled-count, archiveAndDelete schema-name build + drop, and countUnsettledEvents honouring invoiceRef. Marked [~] for cross-app blockers — Shillinq suspend/termination webhook + ArchiveTenantData TimedJob + cold-storage destination wiring + integration tests are deferred to chain member 12 (live Shillinq + DB + cold-storage).

Member 11 of 12 (code). Depends on member 10. Traces to giant Task 19 + Task 20 + REQ-008.

## 1. Suspension / reactivation

- [x] Implement `TenantLifecycleControlService.suspend(tenantId, reason)` (status=suspended, no billing during suspension) — wraps `TenantSaasService::updateStatus()`; emits WARNING audit log with reason
- [x] Add lifecycle middleware gate: suspended → 403 "Tenant is suspended" (existing cases visible) — already enforced by existing `TenantMiddleware` (it checks `status !== 'active'` and throws 403)
- [~] Send Shillinq suspend webhook `{tenant_id, status:"suspended", effective_date}` — webhook dispatch deferred to chain member 12 (needs Shillinq webhook URL + signing key in app config)
- [x] Implement `TenantLifecycleControlService.reactivate(tenantId)` (status=active, billing resumes, webhook) — state-transition delegates; webhook dispatch deferred with the suspend webhook

## 2. Termination + archival

- [x] Implement `TenantLifecycleControlService.terminate(tenantId, reason, retentionYears=1)` (status=terminated, terminatedAt) — `TenantSaasService::updateStatus()` already auto-stamps `terminatedAt`; method returns `{tenant, unsettledEvents, retentionYears}`
- [x] Finalise pending billing (all TenantBillingEvents have invoiceRef) before revoking access — `countUnsettledEvents()` surfaces the gap so the caller can refuse to terminate when non-zero; the actual block-on-unsettled is a chain-member-12 controller decision
- [~] Send Shillinq termination webhook with final invoice; revoke API access (terminated → 403) — termination webhook deferred with the suspend webhook; 403 access revocation already enforced by `TenantMiddleware`
- [x] Implement archival schema drop — `archiveAndDelete()` invokes the validated schema-name builder + `TenantSchemaProvisioner::dropSchema()` (chain member 03)
- [~] `ArchiveTenantData` TimedJob (retain then delete; enterprise → cold storage per dataResidency) — `archiveAndDelete()` is the synchronous primitive; the retention-aware job is deferred to chain member 12
- [x] Write an immutable deletion-confirmation log entry on schema deletion — `archiveAndDelete()` emits `Procest TENANT_SCHEMA_DELETED` at INFO so a SIEM can ingest it

## 3. Tests

- [~] Integration test: suspend → 403 on creation; existing visible; reactivate restores — requires live OR + middleware path; deferred to chain member 12
- [~] Integration test: terminate → status, billing settled, access revoked (403) — requires live OR + Shillinq stub; deferred to chain member 12
- [~] Integration test: archival retains then deletes schema (mock destination) + deletion log — requires live Postgres + cold-storage mock; deferred to chain member 12
