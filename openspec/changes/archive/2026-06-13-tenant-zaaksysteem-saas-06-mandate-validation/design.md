# Design: tenant-zaaksysteem-saas-06-mandate-validation

## Scope of this member

Per-tenant action-level authorization via the mandaat-matrix. Consumes the `TenantMandate` schema (member 01) and the JWT roles (member 05). No new entities.

## Declarative-first (ADR-031, ADR-001, ADR-023)

The mandate *data* (which role grants which action) is declarative — stored as `TenantMandate` referencing an OpenRegister mandaat-matrix record (member 01). The *enforcement* (load matrix, match role+action, allow/deny) is imperative authorization glue — `kind: code` per ADR-032, and aligned with ADR-023 (action-level authorization via admin-configured action/group mappings). The check reads through the OpenRegister `ObjectService`; no bespoke mapper.

## Components

### TenantAuthenticationService.validateMandateMatrix(tenantId, userId, action)
- Loads the active `TenantMandate` for the tenant.
- Resolves the user's role (from JWT / `TenantUser`).
- Looks up whether that role grants the requested action in the matrix.
- Returns `{allowed: bool, reason: string}`.

### MandateValidationMiddleware
- Runs on requests that require a mandate check (case edit, case status transition, delete).
- Calls `validateMandateMatrix`; on deny → HTTP 403 with the reason message.
- Records the decision (allow + deny) to the audit trail.

## Security (ADR-005, ADR-023)

This is an authorization control, so it must fail closed: if the mandate cannot be resolved (no matrix, service error), deny rather than allow (no `catch (\Throwable) { return null; }` fall-open — unsafe-auth-resolver gate). The middleware is the single caller of the validation method; the method is never defined-but-unused (orphan-auth gate). Every decision is audit-logged with tenant_id, user, action, and outcome.

## Tests

- Unit: `validateMandateMatrix` for multiple roles (Behandelaar, Vergunningverlener) × multiple actions (case_create, case_edit, case_status_update, case_delete).
- Unit: fail-closed when no matrix exists or the service errors.
- Integration: middleware blocks an unauthorised action (403) and logs the decision; allows an authorised one.
