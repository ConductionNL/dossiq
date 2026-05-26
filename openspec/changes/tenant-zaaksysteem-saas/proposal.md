# Proposal: tenant-zaaksysteem-saas

## Summary

Enable Procest to operate as a multi-tenant SaaS platform serving multiple municipalities, shared services, and provincial platforms with complete tenant isolation, per-tenant configuration, automated onboarding, and billing integration. This change transforms Procest from a single-instance case management system into a scalable cloud application that can serve 50+ tenants on a single deployment with strict data segregation and customizable workflows.

## Why

Small to mid-sized municipalities (< 100k residents), municipal cooperatives (gemeenschappelijke regelingen), and provincial platforms lack affordable, low-maintenance case management systems. On-premise installations require dedicated IT infrastructure and staff; cloud SaaS solutions cost €50k-200k annually per municipality. Procest-as-a-SaaS delivers a complete solution: one cloud deployment supports unlimited tenants with centralized upgrades, monitoring, and billing. Each tenant sees only their own data, controls their own configuration (zaaktypen, mandates, branding, SSO), and pays based on usage (cases/month or active users). For hosting partners and shared-service organizations, this means zero per-tenant deployment overhead and one code path to upgrade.

Target market: 150+ Dutch municipalities with budgets under €50k/year for case management. Secondary: provincial registers (Provincie Zuid-Holland shared services), disaster-recovery cloud backups for on-prem Procest customers.

## What Changes

1. **Tenant entity and lifecycle** — Tenant resource with slug, displayName, legal name, KVK registration, contract reference, status (onboarding→active→suspended→terminated), tier (basic/standard/enterprise), and residency/isolation mode
2. **Per-tenant data isolation** — Database schema segregation (PostgreSQL `search_path`) or per-tenant database instances; Row Level Security policies for query-level isolation
3. **Tenant onboarding workflow** — Guided checklist: contract signature (Decidesk) → mandate import → SSO setup (eHerkenning/Azure AD) → zaaktype selection → branding → go-live
4. **Per-tenant configuration** — TenantConfiguration with branding (logo, colors, fonts via NL Design System tokens), custom domain (ACME/Let's Encrypt SSL), locale/timezone, feature flags
5. **Per-tenant quotas and billing** — TenantQuota enforces limits (cases/month, storage GB, active users, API calls/hour) in real-time; TenantBillingEvent hooks to Shillinq for factuurgeneratie
6. **Per-tenant theming** — CSS-variable injection for NL Design System compatibility; HTML/Enterprise tier custom CSS with XSS sanitization
7. **Per-tenant SSO and authentication** — Multi-IdP support (eHerkenning, Azure AD per tenant) with SAML/OIDC; tenant context in JWT; mandate matrix validation
8. **Tier management** — basic (100 cases/month), standard (1000/month, custom branding), enterprise (unlimited, dedicated support, BIO baseline, pen-test coverage)

## Impact

- **Affected projects**: procest (primary), openregister (multi-tenant schemas), openconnector (per-tenant credentials vault), decidesk (contract onboarding), shillinq (billing events), nl-design (theming system), keycloak/azure (SSO per tenant)
- **Code surface**: TenantService and API controllers, database migration (ADD search_path / per-tenant schema creation), middleware for tenant context extraction, billing event emission, onboarding workflow UI
- **Breaking changes**: All queries must include tenant-context filter; all session/JWT handling adds tenant claim
- **Dependencies**: PostgreSQL Row Level Security, OpenRegister multi-tenant support (planned ADR-XXX), Let's Encrypt ACME API, Shillinq events API
- **Standards**: Common Ground layer 1 (data level), AVG artikel 28 (verwerkersovereenkomst), BIO 2.0 baseline (enterprise tier), ISO 27001 (SaaS provider)

## Scope

### In Scope — Core Multi-Tenant

- Tenant entity and CRUD API
- Per-tenant schema isolation (PostgreSQL `search_path` method) or database-per-tenant option
- Tenant lifecycle management (onboarding → active → suspended → terminated)
- Tenant context extraction and enforcement (middleware)
- TenantConfiguration (branding, domain, locale, feature flags)

### In Scope — Onboarding & Operations

- Tenant onboarding workflow with guided checklist
- Decidesk contract integration (webflow, signature verification)
- Mandate matrix import (CSV/ZDS format)
- Zaaktype template selection and per-tenant forking
- First user creation and role assignment
- Domain provisioning (wildcard/subdomain CNAME, ACME cert)
- Branding setup and preview

### In Scope — Per-Tenant Customization

- Per-tenant theming (CSS variables, NL Design System tokens)
- Per-tenant SSO configuration (SAML/OIDC endpoint setup)
- Per-tenant quota management with real-time enforcement
- Per-tenant feature flags (beta features, integrations)

### In Scope — Billing

- TenantQuota schema and enforcement (warn/throttle/block)
- TenantBillingEvent emission (case_created, user_activated, storage_increment)
- Billing dashboard for tenant-admin
- Monthly quota reset and calculation

### Out of Scope

- Billing PDF generation (Shillinq responsibility)
- Identity provider administration (eHerkenning/Azure AD federation setup is tenant responsibility, we integrate endpoints)
- Custom per-tenant applications or extensions (covered by future app-marketplace feature)
- Multi-language UI translation (NL only in Phase 1)
- Advanced analytics/reporting (covered by future MyDash integration)

## Dependencies

- **OpenRegister (multi-tenant)** — REQUIRED: OpenRegister must support per-tenant schema scope (see related ADR-XXX)
- **PostgreSQL Row Level Security** — REQUIRED for query-level isolation
- **Keycloak or Azure AD** — For per-tenant SSO endpoint configuration and SAML/OIDC claim validation
- **Let's Encrypt ACME API** — For automated domain provisioning
- **Decidesk** — Contract signature workflow during onboarding
- **Shillinq** — Billing event consumption and invoice generation
- **NL Design System** — Token definitions for per-tenant theming
- **Nextcloud file API** — For logo/document storage per tenant

## Acceptance Criteria

1. GIVEN a new municipality signs up via the self-service portal, WHEN onboarding is initiated, THEN a Tenant record is created with unique slug and database schema (or instance), and a 30-minute checklist guides the admin to go-live
2. GIVEN Tenant A and Tenant B share one database, WHEN users query zaak data, THEN RLS policies and search_path isolation guarantee that Tenant A users never see Tenant B zaken, even with injected filter parameters
3. GIVEN a Tenant A user with eHerkenning role 'Behandelaar', WHEN they login via the tenant's eHerkenning endpoint, THEN the JWT includes `tenant_id = A` and the mandate matrix restricts their actions to Behandelaar permissions on Tenant A only
4. GIVEN Tenant B has tier=standard with logo/colors configured, WHEN a page renders, THEN CSS variables (`--tenant-primary`, `--tenant-secondary`, etc.) are injected and NL Design System components respect them
5. GIVEN Tenant A has quota `cases_per_month = 500`, WHEN they create case 501, THEN the API blocks creation with "Quota exceeded" and emits a TenantBillingEvent for overage
6. GIVEN a case is created on Tenant A, WHEN it is marked closed, THEN a TenantBillingEvent (`case_closed`, quantity=1, unit_price=€4.50) is emitted to Shillinq for invoicing
7. GIVEN Tenant A has an enterprise contract with BIO baseline, WHEN a user action is logged, THEN the audit trail includes additional context (IP, device, geo) for compliance
8. GIVEN Tenant A requests suspension, WHEN the request is processed, THEN all case creation and API calls are throttled/blocked after a grace period, and a termination callback is sent to Shillinq for billing cutoff

## Risks & Mitigations

| Risk | Severity | Mitigation |
|------|----------|-----------|
| Data leak between tenants due to RLS misconfiguration | Critical | Row-level security tests mandatory in CI; pen-test on every release; code review checklist for all query changes |
| Shared-database performance degradation with 50+ tenants | High | Database partitioning by tenant_id; query performance SLA (< 200ms p99); monitoring alerts on slow queries; optional database-per-tenant for enterprise tier |
| Onboarding UX complexity deters small municipalities | Medium | Guided checklist with sensible defaults; skip-able optional steps (SSO, custom domain); sample data templates |
| Billing disputes (tenant claims charge is wrong) | Medium | Real-time billing dashboard showing all events; export billing history; monthly reconciliation email; customer support process for disputes |

## Success Metrics

- 10+ municipalities onboarded in Phase 1 (6 months)
- MTTR for new tenant provisioning < 5 minutes (automated)
- Data isolation verified by quarterly pen-test with zero findings
- Billing revenue tracked; average invoice reconciliation time < 1 hour
- Tenant churn < 5% annually (sticky SaaS is 95%+ retention)
