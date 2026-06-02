# Design: tenant-zaaksysteem-saas-11-suspension-termination

## Scope of this member

Suspension/reactivation and termination/archival side effects on top of member 02's lifecycle state machine. The AVG immutable-deletion-log requirement (REQ-010-C) is asserted by member 12's compliance tests; the deletion mechanism lives here.

## Declarative-first (ADR-031, ADR-001)

Status transitions write the declarative `Tenant` object via the `ObjectService`. The side effects (access gating, webhooks, schema archival/deletion) are imperative lifecycle glue — `kind: code`.

## Service layer

### TenantService.suspend / reactivate
- `suspend(tenantId, reason)` → status=suspended; case-creation/API requests return 403 "Tenant is suspended"; existing cases stay visible; no billing events emitted; Shillinq webhook `{tenant_id, status:"suspended", effective_date}`.
- `reactivate(tenantId)` → status=active; case creation restored; billing resumes; reactivation webhook.

### TenantService.terminate
- `terminate(tenantId, reason, retentionYears=1)` → status=terminated, terminatedAt=now.
- Finalise all pending `TenantBillingEvent` (ensure invoiceRef set via member 10), send Shillinq termination webhook with the final invoice.
- Revoke API access (JWT validation rejects all requests for this tenant).

### ArchiveTenantData (job)
- basic/standard: retain schema offline for retentionYears, then delete.
- enterprise: export to cold storage (S3 Glacier) per dataResidency, then delete.
- After retention: drop the schema; record an immutable deletion-confirmation log entry (consumed by REQ-010-C).

## Lifecycle middleware gate

A status check in the request pipeline: suspended → 403 "Tenant is suspended"; terminated → 403 "This tenant is no longer active." Sits alongside the context/isolation middleware (member 04).

## Security (ADR-005)

Suspend/terminate are SaaS-administrator operations (admin-only). Termination is destructive and irreversible after retention — it MUST verify all billing is settled before revoking access (no orphaned unbilled usage). The deletion-confirmation log is append-only/immutable (tamper-evident, AVG). Webhook calls to Shillinq use secure credentials, never logged.

## Tests

- Integration: suspend → 403 on case creation; existing cases visible; reactivate restores.
- Integration: terminate → status=terminated, final billing settled, access revoked (403).
- Integration: archival job retains then deletes schema after retention (mock destination); immutable deletion log written.
