# Design: tenant-zaaksysteem-saas-01-schemas-and-seed

## Scope of this member

Declarative data-model foundation for the whole chain: seven OpenRegister schemas, their register template registration, and seed tier-template + default-tenant data. No service code, no controllers, no middleware — those land in members 02+.

## Declarative-vs-imperative decision (ADR-031)

The tenant data model is **declarative** (ADR-031, ADR-001): the seven entities are register schemas in OpenRegister, not bespoke Doctrine entities. All later CRUD goes through the OpenRegister `ObjectService` (ADR-001), so the schemas are the single source of truth for shape, validation, and the auto-maintained audit trail. `TenantBillingEvent` is insert-only (billing immutability) — modelled as an append-only schema. Tenant isolation itself (search_path / RLS) is an access-layer concern handled by code member 04; the schemas carry no per-row tenant scoping logic.

## New entity schemas (OpenRegister)

### Tenant
Properties: `slug` (req, unique), `displayName` (req), `legalName`, `kvkNumber`, `contractRef`, `status` enum {onboarding, active, suspended, terminated} (req), `tier` enum {basic, standard, enterprise} (req), `isolationMode` enum {schema, database}, `dataResidency` enum {nl, eu}, `createdAt`, `activatedAt`, `terminatedAt`.

### TenantConfiguration
Properties: `tenantRef` (req), `branding` (JSON: logo, primaryColor, secondaryColor, fontFamily, customCSS), `domain`, `locale`, `timezone`, `dateFormat`, `currency`, `features` (array of feature-flag names).

### TenantQuota
Properties: `tenantRef` (req), `quotaType` enum {cases_per_month, storage_gb, active_users, api_calls_per_hour} (req), `limit`, `currentUsage`, `resetAt`, `softLimitWarningPercent`, `enforcement` enum {warn, throttle, block}.

### TenantUser
Properties: `tenantRef` (req), `userRef` (req), `role`, `joinedAt`, `lastActiveAt`, `mfaEnabled`, `eherkenningLevel` enum {2, 3, 4}.

### TenantMandate
Properties: `tenantRef` (req), `mandateMatrixRef`, `effectiveFrom`, `effectiveTo`, `signedBy`, `documentRef`.

### TenantBillingEvent (insert-only)
Properties: `tenantRef` (req), `eventType` enum {case_created, case_closed, user_activated, storage_increment, api_burst, quota_exceeded, case_refund} (req), `quantity`, `unitPrice`, `currency`, `occurredAt` (req), `invoiceRef` (nullable).

### TenantOnboardingTask
Properties: `tenantRef` (req), `step` enum {contract, mandate_import, sso_setup, branding, zaaktype_selection, first_user, go_live} (req), `status` enum {pending, in_progress, completed, skipped} (req), `completedBy`, `completedAt`, `blockedReason`.

## Relations

- Tenant → TenantConfiguration (1:1)
- Tenant → TenantQuota (1:many, one per quotaType)
- Tenant → TenantUser (1:many)
- Tenant → TenantMandate (1:many)
- Tenant → TenantBillingEvent (1:many, immutable)
- Tenant → TenantOnboardingTask (1:many, one per step)

## Seed data

Register template seeds:
1. **Tier quota templates** — basic (cases_per_month=100, storage_gb=10, active_users=5, api_calls_per_hour=1000), standard (1000/100/50/10000), enterprise (all unlimited / NULL). Used by member 09 to materialise `TenantQuota` rows on go-live.
2. **Default-tenant onboarding template** — the seven `TenantOnboardingTask` steps in `pending` state, forked per tenant by member 07.

Seed lands via the fleet repair-step import pattern (`lib/Repair/InitializeRegister.php` + `<repair-steps>` in `info.xml`), NOT via a migration, so OpenRegister autoloaders are available at import time.

## Security (ADR-005)

Schema declarations carry no endpoints. The seed import runs as a repair step (admin/system context). Read/write access control for the entities is enforced by OpenRegister RBAC (ADR-022) and asserted by the consuming code members. Per-tenant query isolation (search_path/RLS) is member 04's concern.

## Integration test

One integration test asserts: the seven schemas materialise with the documented required properties; the tier quota templates and the default-tenant onboarding template are queryable via the OpenRegister REST API after the repair step runs; and a tenant-scoped query through OpenRegister returns only the requesting tenant's rows (REQ-009 materialisation slice).
