# Design: tenant-zaaksysteem-saas-02-tenant-crud-lifecycle

## Scope of this member

Tenant CRUD API + lifecycle state machine. Reads/writes the `Tenant` schema from member 01 through OpenRegister. No provisioning (member 03), no isolation (member 04), no onboarding (member 07).

## Declarative-first (ADR-031, ADR-001)

All `Tenant` persistence goes through the OpenRegister `ObjectService` — `find`, `findAll`, `saveObject`, `updateObject`, `deleteObject`. No bespoke Doctrine entity or mapper; the schema declared in member 01 is the canonical shape. The lifecycle state machine is thin imperative glue around `updateStatus`, validating that only legal transitions are written.

## Service layer

### TenantService
- `create(name, kvkNumber, tier)` → generates slug, sets status=onboarding, createdAt=now, activatedAt=NULL, contractRef=NULL, isolationMode by tier; persists via ObjectService.
- `getById(tenantId)` → full tenant metadata.
- `listActive(statusFilter?)` → paginated list, filterable by status.
- `updateStatus(tenantId, newStatus)` → validates the transition against the state machine, persists.

### Slug generation
`slugify(name)` → lowercased, non-alphanumerics → hyphens, collapsed, trimmed, max 64 chars. Uniqueness checked against existing `Tenant.slug`; on collision the create fails with "Slug already exists".

## Lifecycle state machine

Legal transitions: `onboarding → active`, `active → suspended`, `suspended → active`, `active → terminated`, `suspended → terminated`. Any other transition is rejected. The suspend/reactivate/terminate side effects (webhooks, access revocation) are member 11; this member only enforces the transition graph and the `updateStatus` write.

## API routes (ADR-016)

Registered in `appinfo/routes.php`:
```
POST   /api/tenants            → TenantController.create()
GET    /api/tenants/{tenantId} → TenantController.show()
PATCH  /api/tenants/{tenantId} → TenantController.update()
DELETE /api/tenants/{tenantId} → TenantController.destroy()
```

## Security (ADR-005)

Tenant CRUD is a SaaS-administrator operation, not a tenant-user operation. Controller methods are admin-only (no `#[NoAdminRequired]`; the NC SecurityMiddleware default enforces admin-only). Input is validated (slug format, tier enum, status enum) before any write. No tenant-scoped IDOR surface here — these endpoints operate on the platform-level `Tenant` registry, not on per-tenant data.

## Tests

- Unit: slug generation (lowercasing, hyphenation, 64-char cap), uniqueness collision.
- Unit: lifecycle transition validation (legal accepted, illegal rejected).
- Integration: full CRUD round-trip through OpenRegister, list filtering by status.
