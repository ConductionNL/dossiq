# Design: tenant-zaaksysteem-saas

## Architecture

The tenant-zaaksysteem-saas feature adds a tenant-isolation and multi-account layer to Procest without forking core case management logic. All case, workflow, document, and decision entities remain in the base OpenRegister schemas; tenant isolation is enforced at three levels:

1. **Database level**: PostgreSQL `search_path` per request (or per-database instances for enterprise)
2. **ORM level**: Tenant context injected into all Eloquent queries via middleware
3. **API level**: JWT tenant claim validation; tenant context in request context

```
OpenRegister Schema                 Procest SaaS Tenant Layer
├─ case                             ├─ TenantService
├─ caseType                         ├─ TenantConfigurationService
├─ decision                         ├─ TenantQuotaService
├─ workflowTemplate                 ├─ TenantBillingService
├─ ... (shared by all tenants)      ├─ TenantOnboardingService
                                    ├─ TenantsController
Tenant-specific data                ├─ AuthenticationMiddleware (tenant extraction)
├─ Tenant                          └─ TenantContext (request-scoped)
├─ TenantConfiguration
├─ TenantQuota
├─ TenantBillingEvent
├─ TenantOnboardingTask
├─ TenantUser
└─ TenantMandate
```

## Database Isolation Strategy

### Option 1: Schema-per-tenant (Default, Phase 1)

- Single PostgreSQL database, separate schema per tenant (e.g., `tenant_a_schema`, `tenant_b_schema`)
- All application tables (case, caseType, decision, etc.) replicated per schema
- Shared tables (Tenant, TenantConfiguration) in `public` schema
- Middleware sets `SET search_path = 'public,tenant_a_schema'` for each request based on JWT
- Advantages: cost-effective, easy initial scale, shared infrastructure
- Disadvantages: slower query routing, data residency harder (both schemas in same DB instance)

### Option 2: Database-per-tenant (Enterprise tier, Phase 2)

- Separate PostgreSQL instance or RDS database per tenant
- Higher isolation and compliance (data residency, BIO baseline)
- Advantages: zero data leakage risk, independent backup/restore, geo-isolation options
- Disadvantages: higher cost (RDS instances multiply), operational complexity

## Service Layer

### TenantService
- `create(name, slug, kvkNumber, tier)` → Tenant with lifecycle state machine
- `getById(tenantId)` → full tenant metadata
- `listActive()` → paginated list (admin dashboard)
- `updateStatus(tenantId, newStatus)` → onboarding→active→suspended→terminated
- `provision(tenantId)` → creates schema/database, seeds templates, configures domain
- `deprovision(tenantId)` → archives data, removes schema (on termination)

### TenantConfigurationService
- `getConfig(tenantId)` → TenantConfiguration (branding, locale, features)
- `updateBranding(tenantId, logo, primaryColor, secondaryColor, fontFamily, customCSS)` → validates CSS, stores
- `updateDomain(tenantId, domain)` → ACME request, Let's Encrypt cert, DNS validation
- `setFeatureFlag(tenantId, feature, enabled)` → real-time feature gates
- `getThemingTokens(tenantId)` → CSS variable map for frontend

### TenantQuotaService
- `getQuota(tenantId, quotaType)` → returns limit, currentUsage, resetAt
- `checkLimit(tenantId, quotaType, amount)` → returns {allowed: true/false, remaining}
- `increment(tenantId, quotaType, amount)` → increments usage; blocks if exceeded
- `reset(quotaType)` → nightly job to reset monthly quotas
- `softLimitWarning(tenantId, quotaType)` → sends admin email at 80% usage

### TenantBillingService
- `emitEvent(tenantId, eventType, quantity, unitPrice)` → creates TenantBillingEvent
- `getMonthBilling(tenantId, month)` → aggregates all events for month
- `exportToShillinq()` → daily job publishing events to Shillinq API for invoicing
- `getDashboard(tenantId)` → summary for tenant admin (YTD spend, forecasted, current quota)

### TenantOnboardingService
- `createOnboarding(tenantId)` → initializes checklist with all steps (pending)
- `getProgress(tenantId)` → returns completed/pending steps with helper links
- `markStepComplete(tenantId, step)` → validates prerequisites, marks step done
- `validateGoLive(tenantId)` → checks mandatory fields (≥1 zaaktype, ≥1 mandaat, ≥1 user) before activating tenant
- `autoSeedTemplates(tenantId)` → seeds standard zaaktype templates, mandaat-matrix template, default roles

### TenantAuthenticationService
- `resolveIdpEndpoint(tenantId)` → returns configured SAML/OIDC endpoint for tenant
- `validateTenantClaim(jwt, tenantId)` → ensures JWT tenant_id matches request tenant
- `validateMandateMatrix(tenantId, userId, action)` → checks SAML/eHerkenning claims against tenant's mandaat-matrix

### TenantUserService
- `addUser(tenantId, email, role)` → creates TenantUser with role binding
- `removeUser(tenantId, userId)` → removes from tenant (audit-logged)
- `getRoleBinding(tenantId, userId)` → returns role + eHerkenning level / AD group
- `countActiveUsers(tenantId)` → active in last 30 days (for quota enforcement)

## Data Model

### Core Tenant Entities (OpenRegister Schemas)

**Tenant**
- `id` (uuid, PK)
- `slug` (string, unique, URL-safe) — e.g. "gemeente-groningen"
- `displayName` (string) — e.g. "Gemeente Groningen"
- `legalName` (string) — e.g. "Gemeentebestuur van Groningen"
- `kvkNumber` (string, optional) — Chamber of Commerce registration
- `contractRef` (string, optional) — Link to Decidesk contract
- `status` (enum) — onboarding | active | suspended | terminated
- `tier` (enum) — basic | standard | enterprise
- `isolationMode` (enum) — schema | database
- `dataResidency` (enum) — nl | eu
- `createdAt` (timestamp)
- `activatedAt` (timestamp, nullable)
- `terminatedAt` (timestamp, nullable)

**TenantConfiguration**
- `tenantRef` (uuid, FK→Tenant)
- `branding` (json) — {logo: url, primaryColor: #RGB, secondaryColor: #RGB, fontFamily: string, customCSS: string}
- `domain` (string, optional) — e.g. "gemeente-groningen.zaaksysteem.nl"
- `locale` (string) — nl_NL, en_US
- `timezone` (string) — Europe/Amsterdam
- `dateFormat` (string) — DD-MM-YYYY
- `currency` (string) — EUR
- `features` (array) — [feature_flag_names] for beta/disabled features

**TenantQuota**
- `tenantRef` (uuid, FK→Tenant)
- `quotaType` (enum) — cases_per_month | storage_gb | active_users | api_calls_per_hour
- `limit` (integer) — max allowed
- `currentUsage` (integer) — current month/period usage
- `resetAt` (timestamp) — next reset time (monthly, or custom per quota type)
- `softLimitWarningPercent` (integer) — warn at 80% by default
- `enforcement` (enum) — warn | throttle | block

**TenantUser**
- `tenantRef` (uuid, FK→Tenant)
- `userRef` (uuid, FK→User in Nextcloud)
- `role` (string) — tenant_admin | case_handler | viewer | etc.
- `joinedAt` (timestamp)
- `lastActiveAt` (timestamp, nullable)
- `mfaEnabled` (boolean)
- `eherkenningLevel` (enum, nullable) — 2 | 3 | 4 (per eHerkenning spec)

**TenantMandate**
- `id` (uuid, PK)
- `tenantRef` (uuid, FK→Tenant)
- `mandateMatrixRef` (string) — reference to OpenRegister mandaat-matrix record
- `effectiveFrom` (date)
- `effectiveTo` (date, nullable)
- `signedBy` (string) — person/role who signed the mandate
- `documentRef` (string) — file reference (CSV, PDF, etc.)

**TenantBillingEvent**
- `id` (uuid, PK)
- `tenantRef` (uuid, FK→Tenant)
- `eventType` (enum) — case_created | case_closed | user_activated | storage_increment | api_burst
- `quantity` (integer) — number of units (e.g., 1 case, 5 GB)
- `unitPrice` (decimal) — EUR, could be zero for some event types
- `currency` (string) — EUR
- `occurredAt` (timestamp) — when the event happened
- `invoiceRef` (string, nullable) — set after Shillinq generates invoice

**TenantOnboardingTask**
- `id` (uuid, PK)
- `tenantRef` (uuid, FK→Tenant)
- `step` (enum) — contract | mandate_import | sso_setup | branding | zaaktype_selection | first_user | go_live
- `status` (enum) — pending | in_progress | completed | skipped
- `completedBy` (uuid, nullable, FK→User)
- `completedAt` (timestamp, nullable)
- `blockedReason` (string, nullable) — explanation if blocked/incomplete

### OpenRegister Schema Extensions

All existing schemas (case, caseType, decision, document, etc.) gain implicit `tenant_id` context via:
- Database search_path (schema-per-tenant method)
- Row-level security policies (database-per-tenant method, or additional RLS layer)
- Query middleware injection (ORM-level)

No schema changes needed to existing entities; isolation is enforced at access layer.

## API Routes

### Tenant Admin Routes

```
POST   /api/tenants                    → TenantController.create()
GET    /api/tenants/{tenantId}         → TenantController.show()
PATCH  /api/tenants/{tenantId}         → TenantController.update()
DELETE /api/tenants/{tenantId}         → TenantController.destroy()

GET    /api/tenants/{tenantId}/config                → TenantConfigurationController.show()
PATCH  /api/tenants/{tenantId}/config                → TenantConfigurationController.update()
POST   /api/tenants/{tenantId}/config/branding       → BrandingController.update()
POST   /api/tenants/{tenantId}/config/domain         → DomainController.provision()
GET    /api/tenants/{tenantId}/config/theming-tokens → BrandingController.getTokens()

GET    /api/tenants/{tenantId}/quotas               → TenantQuotaController.index()
PATCH  /api/tenants/{tenantId}/quotas/{quotaType}   → TenantQuotaController.update()

GET    /api/tenants/{tenantId}/billing              → TenantBillingController.dashboard()
GET    /api/tenants/{tenantId}/billing/events       → TenantBillingController.events()
GET    /api/tenants/{tenantId}/billing/export       → TenantBillingController.export()

GET    /api/tenants/{tenantId}/onboarding           → TenantOnboardingController.progress()
POST   /api/tenants/{tenantId}/onboarding/{step}/complete → TenantOnboardingController.markStepComplete()
POST   /api/tenants/{tenantId}/onboarding/go-live   → TenantOnboardingController.goLive()

GET    /api/tenants/{tenantId}/users                → TenantUserController.index()
POST   /api/tenants/{tenantId}/users                → TenantUserController.store()
DELETE /api/tenants/{tenantId}/users/{userId}       → TenantUserController.destroy()
```

### Tenant-Scoped Case Routes (inherited from existing procest)

All case-related routes automatically scoped to request tenant:
```
POST   /api/cases                      → filtered by JWT tenant_id, quotas enforced
GET    /api/cases/{caseId}             → 403 if caseId belongs to different tenant
PATCH  /api/cases/{caseId}             → quota + mandate checks
DELETE /api/cases/{caseId}             → audit-logged
```

## Authentication & Tenant Context Injection

### JWT Structure (extending existing)

```json
{
  "sub": "user-uuid",
  "email": "handler@gemeente.nl",
  "tenant_id": "tenant-uuid",
  "tenant_slug": "gemeente-groningen",
  "roles": ["case_handler"],
  "eherkenning_level": 3,
  "iat": 1234567890,
  "exp": 1234571490
}
```

### Middleware Pipeline

1. **AuthenticateMiddleware** — Validates JWT signature, checks expiry
2. **TenantContextMiddleware** — Extracts tenant_id from JWT, resolves Tenant record, injects into request
3. **TenantIsolationMiddleware** — Enforces RLS/search_path per tenant; blocks cross-tenant access
4. **QuotaEnforcementMiddleware** — Checks quota before case creation, billing events, API calls
5. **MandateValidationMiddleware** — Cross-references JWT claims against tenant's mandate matrix

## Onboarding Workflow State Machine

```
[New Signup]
    ↓
[contract] — Decidesk webflow signature → contract-signed
    ↓ (auto-transition)
[mandate_import] — Admin uploads CSV/ZDS mandate matrix
    ↓ (can skip for on-prem only)
[sso_setup] — Admin configures eHerkenning/Azure AD endpoints
    ↓ (can skip for local auth)
[branding] — Admin uploads logo, colors, domain
    ↓ (optional; defaults to generic branding)
[zaaktype_selection] — Admin chooses from templates or imports custom
    ↓ (required ≥1)
[first_user] — Admin creates first tenant user account
    ↓ (required ≥1)
[go_live] — System validates readiness, tenant status → active
    ↓
[active] — Tenant fully operational
    ├→ [suspended] — Admin suspension or non-payment
    └→ [terminated] — End of contract
```

## Theming System

Per-tenant theming leverages NL Design System:

1. **Branding Storage**: TenantConfiguration.branding holds logo URL, hex colors, font name
2. **Token Generation**: TenantConfigurationService.getThemingTokens() converts branding to CSS variables:
   ```css
   :root {
     --tenant-primary: #0066cc;
     --tenant-secondary: #ff6600;
     --tenant-font-family: "Open Sans", sans-serif;
     --tenant-logo-url: "url('https://...")';
   }
   ```
3. **Frontend Integration**: All views import tenant tokens stylesheet; NL Design System components respect CSS vars
4. **Enterprise Custom CSS**: Enterprise tier can add custom CSS rules (sanitized for XSS via CSS parser whitelist)

## Billing Integration with Shillinq

1. **Event Emission**: TenantBillingService.emitEvent() creates TenantBillingEvent in DB
2. **Nightly Job**: 02:00 UTC, `export-billing-events` job:
   - Queries TenantBillingEvent records with invoiceRef=NULL
   - Calls Shillinq API to create/append invoice line items
   - On success, sets invoiceRef to Shillinq invoice ID
3. **Dashboard**: TenantBillingController.dashboard() aggregates YTD, current month, forecasted spend
4. **Reconciliation**: Monthly email with invoice summary; tenant can dispute within 14 days

## Security Model

### Data Isolation Guarantees

- **Query-level**: Row-level security policies on shared-database setups; database-level for per-database tenants
- **Code-level**: Tenant context injected via middleware; all Eloquent models automatically filtered
- **API-level**: Tenant claim validated on every request; cross-tenant requests return 403

### Audit Logging

- All tenant data access (case view, edit, delete) logged in auditTrail
- Tenant provisioning/deprovisioning events logged to event stream
- Billing events immutable (insert-only)
- Quarterly pen-test to verify isolation

### Compliance

- **BIO 2.0**: Enterprise tier logs IP, device, geo context
- **AVG**: Verwerkersovereenkomst per tenant in metadata; deletion honored per tenure contract
- **ISO 27001**: Encryption at rest, TLS in transit, MFA for admins
