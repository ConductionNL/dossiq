# Specs: tenant-zaaksysteem-saas

Detailed requirements for multi-tenant SaaS enablement, covering tenant provisioning, data isolation, onboarding, configuration, quotas, and billing.

---

## REQ-001: Tenant Provisioning and Schema Isolation

**Purpose**: Create new tenants with guaranteed data isolation via database schemas or instances.

### REQ-001-A: Tenant Creation and Unique Slug

GIVEN a SaaS administrator initiates new tenant creation
WHEN they submit name="Gemeente Groningen", kvkNumber="34251000", tier="standard"
THEN:
- A Tenant record is created with auto-generated UUID
- slug = "gemeente-groningen" (lowercased, hyphens, unique in Tenant table)
- status = "onboarding", createdAt = now, activatedAt = NULL
- contractRef = NULL (awaiting Decidesk signature)
- isolationMode is set based on tier (schema for basic/standard, optional database for enterprise)

GIVEN a second admin tries to create another tenant with slug="gemeente-groningen"
WHEN the request is submitted
THEN creation fails with error "Slug already exists"

### REQ-001-B: Schema Provisioning (Schema-per-Tenant)

GIVEN Tenant creation is confirmed and Decidesk contract is signed
WHEN TenantService.provision(tenantId) is called
THEN:
- A new PostgreSQL schema is created with name = "tenant_{uuid}_{slug}" (max 63 chars)
- All application tables (case, caseType, decision, document, workflowTemplate, etc.) are cloned into the tenant schema
- Shared tables (Tenant, TenantConfiguration, TenantQuota, etc.) remain in public schema
- Standard zaaktype templates are seeded into tenant schema (via OpenRegister seed job)
- Default mandaat-matrix template is created with basic role definitions
- Default roles (tenant_admin, case_handler, viewer) are created in roleType
- A "Welcome to Zaaksysteem" email is sent to the provided tenant admin email with login link

GIVEN schema provisioning is complete
WHEN a user logs in with tenant_id claim matching this tenant
THEN the application automatically sets `SET search_path = 'public,tenant_{uuid}_{slug}'` before any SQL queries

### REQ-001-C: Database-per-Tenant (Enterprise Tier, Phase 2)

GIVEN a tenant with tier="enterprise" and isolationMode="database"
WHEN provisioning is initiated
THEN:
- A separate RDS database instance (or postgres schema in isolated instance) is created
- Database name format: `proc_tenant_{slug}_{uuid_short}`
- Database credentials are generated and stored in secure vault (per-tenant)
- All schemas (case, caseType, etc.) are initialized in the new database
- Replication/backup rules are set per tenant's dataResidency preference (NL or EU)
- Connection pooling is configured per database to prevent exhaustion

---

## REQ-002: Data Isolation and Row-Level Security

**Purpose**: Guarantee that users of one tenant cannot see, modify, or delete data belonging to another tenant.

### REQ-002-A: Query-Level Isolation via search_path

GIVEN User-A (tenant_id=A) and User-B (tenant_id=B) both query the case table simultaneously
WHEN User-A executes `SELECT * FROM case`
THEN:
- Application middleware sets `search_path = 'public,tenant_A_schema'` before query
- User-A sees only cases where the case record physically resides in tenant_A_schema
- User-B (in parallel request) has `search_path = 'public,tenant_B_schema'`
- User-B sees only cases in tenant_B_schema
- Even if User-A attempts malicious filter `WHERE tenant_id='B'`, the table doesn't exist in their search_path

### REQ-002-B: API-Level Tenant Claim Validation

GIVEN a JWT with claim `tenant_id="A"` and request path `/api/cases/case-xyz`
WHEN the request is routed
THEN:
- TenantContextMiddleware extracts tenant_id from JWT
- If the case record (case-xyz) exists in a different tenant's schema, querying it via search_path returns 0 rows
- Application returns HTTP 404 (not found) to the client, not 403 (forbidden), to avoid leaking tenant existence

GIVEN an attacker attempts to forge a JWT with `tenant_id="A"` but without valid signature
WHEN the request arrives
THEN:
- AuthenticateMiddleware validates JWT signature against configured key
- If signature is invalid, request is rejected with HTTP 401 Unauthorized
- Attempted JWT modification/forgery is logged as security incident

### REQ-002-C: Audit Trail for Cross-Tenant Access Attempts

GIVEN an attacker manually modifies their JWT to claim tenant_id="B" (keeping valid signature)
WHEN they attempt to query a case
THEN:
- No data breach occurs (case doesn't exist in their schema)
- Access attempt is logged with IP, timestamp, attempted tenant_id, and requesting user
- Log entry is flagged as "unauthorized_cross_tenant_access"
- Security team is alerted if >5 attempts in 1 hour

### REQ-002-D: Mandate Matrix Validation

GIVEN User-A is assigned role "case_handler" on Tenant A via eHerkenning claim
WHEN they attempt to update a case (PATCH /api/cases/{id})
THEN:
- TenantAuthenticationService.validateMandateMatrix(tenant_A, user_id, "case_edit") is called
- Mandaat-matrix record for Tenant A is checked for this user's role and the "case_edit" action
- If mandate grants permission, update proceeds
- If mandate denies permission, request is blocked with HTTP 403 Forbidden and reason message

---

## REQ-003: Tenant Onboarding Workflow

**Purpose**: Guide new tenants from signup through activation with a structured checklist.

### REQ-003-A: Onboarding Checklist Initialization

GIVEN a new Tenant is created with status="onboarding"
WHEN TenantOnboardingService.createOnboarding(tenantId) is called
THEN:
- A checklist is initialized with steps:
  1. contract (Decidesk contract signature)
  2. mandate_import (Upload mandate CSV)
  3. sso_setup (Configure eHerkenning/Azure AD)
  4. branding (Logo, colors, domain)
  5. zaaktype_selection (Choose case type templates)
  6. first_user (Create first tenant admin)
  7. go_live (Confirm readiness; activate tenant)
- All steps start with status="pending"
- Tenant admin receives email with checklist link and instructions

### REQ-003-B: Contract Signature via Decidesk

GIVEN tenant admin receives onboarding email
WHEN they click the "Sign contract" link in the checklist
THEN:
- They are redirected to Decidesk with pre-filled tenant details (name, KVK, address)
- Decidesk generates a signature request; tenant admin e-signs the contract
- On successful signature, Decidesk sends webhook to Procest: `POST /webhooks/decidesk/contract-signed`
- Procest TenantController.onContractSigned(tenantId, decidesk_contract_id) is called
- Tenant.contractRef is set to the Decidesk contract ID
- TenantOnboardingTask "contract" is marked status="completed"
- Tenant admin is notified "Contract signed; proceed to Mandate Import"

GIVEN tenant admin has not signed within 14 days of onboarding start
WHEN the nightly job checks for inactive onboardings
THEN:
- A reminder email is sent: "Your contract is awaiting signature. Sign by [date] to keep onboarding slot reserved."

### REQ-003-C: Mandatory Prerequisites for Go-Live

GIVEN Tenant A has completed all steps except mandate_import and sso_setup
WHEN tenant admin attempts to mark "go_live" complete
THEN:
- TenantOnboardingService.validateGoLive(tenantId) checks:
  1. ≥1 zaaktype configured (not in draft state)
  2. ≥1 mandaat-matrix record created (can be skipped if using local auth only)
  3. ≥1 user with role "tenant_admin" created
- If all checks pass, tenant status transitions from "onboarding" to "active"
- If any check fails, validation error lists missing prerequisites

GIVEN all prerequisites are met
WHEN tenant admin confirms "Go Live"
THEN:
- Tenant.status="active"
- Tenant.activatedAt = now
- TenantOnboardingTask "go_live" is marked status="completed"
- Billing start date is set in TenantQuota records
- All users with TenantUser records gain access to case management
- Public-facing domain (if configured) is made live

### REQ-003-D: Onboarding Progress Dashboard

GIVEN a tenant admin logs in during onboarding
WHEN they access the onboarding dashboard
THEN:
- Progress bar shows X/7 steps completed
- Each step is listed with status badge (Pending, In Progress, Completed, Skipped)
- Completed steps show timestamp and completed-by user name
- Next recommended step is highlighted with call-to-action button
- Help sidebar shows contextual tips for current step

---

## REQ-004: Tenant Configuration and Branding

**Purpose**: Allow each tenant to customize their Procest instance with branding and operational settings.

### REQ-004-A: Branding Configuration

GIVEN tenant admin accesses Branding settings
WHEN they upload logo (PNG/SVG, max 5MB) and select colors (primary #0066CC, secondary #FF6600)
THEN:
- Logo is stored as Nextcloud file with tenant-scoped file ID
- TenantConfiguration.branding JSON is updated: `{logo: {id, url}, primaryColor: "#0066CC", secondaryColor: "#FF6600"}`
- Color choices are validated as valid hex colors
- User receives confirmation "Branding saved; changes visible in 1 minute"

GIVEN branding is updated
WHEN subsequent page renders occur
THEN:
- Frontend fetches `GET /api/tenants/{tenantId}/config/theming-tokens` which returns:
  ```json
  {
    "--tenant-primary": "#0066CC",
    "--tenant-secondary": "#FF6600",
    "--tenant-logo-url": "url('https://nextcloud-cdn/file-id')"
  }
  ```
- Frontend injects CSS into `<head>`: `<style>:root { --tenant-primary: #0066CC; ... }</style>`
- NL Design System components (`nlButton`, `nlCard`, etc.) automatically use these variables

### REQ-004-B: Domain Configuration with Let's Encrypt

GIVEN tenant admin enters custom domain "groningen.zaaksysteem.nl"
WHEN they request domain provisioning
THEN:
- System validates domain ownership by sending ACME challenge
- Let's Encrypt is contacted for certificate issuance
- CNAME record `groningen.zaaksysteem.nl` → `zaaksysteem.nl` is created in DNS
- SSL certificate is installed in load balancer/reverse proxy
- Domain is tested with HTTP and HTTPS requests
- Admin receives confirmation; domain is live

GIVEN SSL certificate is issued
WHEN users navigate to `https://groningen.zaaksysteem.nl/`
THEN:
- TenantContextMiddleware extracts tenant from subdomain via lookup (groningen → tenant_id)
- JWT is issued with tenant_id claim
- User sees branding specific to that tenant

### REQ-004-C: Locale and Timezone Settings

GIVEN tenant admin sets locale="nl_NL", timezone="Europe/Amsterdam", dateFormat="DD-MM-YYYY"
WHEN these are saved to TenantConfiguration
THEN:
- All case timestamps render in Europe/Amsterdam timezone
- Dates display as DD-MM-YYYY format (e.g., "14-05-2026")
- Email notifications to users of this tenant are sent with appropriate date formatting

### REQ-004-D: Feature Flags

GIVEN tenant admin accesses Feature Flags section
WHEN they enable feature "workflow_engine_beta=true"
THEN:
- TenantConfiguration.features array includes "workflow_engine_beta"
- Frontend checks `hasFeatureFlag(tenantId, 'workflow_engine_beta')` before showing beta UI
- If feature is disabled, beta UI elements are hidden from all users of this tenant
- Feature flag changes take effect immediately (no cache invalidation wait)

---

## REQ-005: Resource Quotas and Real-Time Enforcement

**Purpose**: Enforce usage limits per tenant based on tier, with warnings and hard blocks.

### REQ-005-A: Quota Type Definitions

GIVEN each Tenant with tier="basic" is created
WHEN TenantQuotaService.initialize(tenantId) is called
THEN:
- TenantQuota records are created for:
  1. cases_per_month: limit=100, currentUsage=0, enforcement="block"
  2. storage_gb: limit=10, currentUsage=0, enforcement="warn"
  3. active_users: limit=5, currentUsage=0, enforcement="warn"
  4. api_calls_per_hour: limit=1000, currentUsage=0, enforcement="throttle"
- For tier="standard": cases_per_month=1000, storage_gb=100, active_users=50
- For tier="enterprise": limits are NULL (unlimited)
- resetAt is set to first day of next month (monthly reset cycle)

### REQ-005-B: Quota Enforcement on Case Creation

GIVEN a tenant with cases_per_month quota of 100 cases
WHEN they have already created 95 cases this month
AND they attempt to create case #96
THEN:
- QuotaEnforcementMiddleware.checkQuota('cases_per_month', 1) is called
- Current usage (95) + requested (1) = 96 < limit (100) → allowed
- Case creation proceeds
- currentUsage is incremented to 96

GIVEN the same tenant attempts to create case #101 (exceeding limit)
WHEN POST /api/cases is called
THEN:
- QuotaEnforcementMiddleware detects currentUsage (100) + requested (1) > limit (100)
- enforcement="block" → API returns HTTP 429 Too Many Requests
- Response body: `{error: "Quota limit exceeded", quota_type: "cases_per_month", limit: 100, current_usage: 100}`
- A TenantBillingEvent is recorded: `{eventType: "quota_exceeded", amount: 1}`
- Tenant admin receives email: "You've reached your monthly case limit. Upgrade to Standard tier for 1,000 cases/month."

### REQ-005-C: Soft Limit Warnings

GIVEN a tenant with cases_per_month quota of 100 and softLimitWarningPercent=80
WHEN they create case #80
THEN:
- QuotaEnforcementMiddleware detects currentUsage (80) >= limit * softLimitWarningPercent (80)
- A warning email is sent to tenant admin: "You've used 80% of your monthly case quota (80 of 100). Upgrade soon to avoid hitting the limit."

### REQ-005-D: Monthly Quota Reset

GIVEN today is 2026-06-01 at 01:00 UTC
WHEN the nightly quota-reset job runs
THEN:
- For all TenantQuota records with quotaType in ['cases_per_month', 'api_calls_per_hour', ...] (monthly reset types):
  - currentUsage is reset to 0
  - resetAt is updated to 2026-07-01 01:00 UTC
- Storage and active-user quotas may have different reset cycles (not reset monthly)

### REQ-005-E: Tier Upgrade and Immediate Effect

GIVEN tenant currently on tier="basic" (100 cases/month)
WHEN they upgrade to tier="standard" (1000 cases/month)
THEN:
- Tier change is processed immediately
- TenantQuota for cases_per_month is updated: limit=1000
- Within 1 minute, quota enforcement uses new limit
- New case creation attempts can succeed up to 1000/month
- Billing service emits event `tier_upgraded_basic_to_standard`

---

## REQ-006: Per-Tenant SSO and Authentication

**Purpose**: Support multiple identity providers (eHerkenning, Azure AD, etc.) per tenant.

### REQ-006-A: Tenant-Specific IdP Configuration

GIVEN a tenant admin accesses SSO settings
WHEN they configure SAML endpoint: https://eherkenning.logius.nl/SAML/metadata
THEN:
- TenantConfiguration stores IdP endpoint metadata
- Metadata is cached locally for performance
- Admin clicks "Test connection" → a test SAML request is sent to IdP
- IdP responds; metadata is validated
- Configuration is saved; subsequent logins for this tenant route through this IdP

GIVEN another tenant admin configures their own Azure AD endpoint: https://login.microsoftonline.com/[tenant-id]/saml
THEN:
- This tenant's IdP config is stored separately
- Users of this tenant are routed to Azure AD; users of other tenants are not

### REQ-006-B: JWT Tenant Claim Injection

GIVEN a user logs in via eHerkenning (tenant_id=A, user@gemeente-groningen.nl)
WHEN eHerkenning returns SAML assertion with eherkenning_level=3, roles=[Behandelaar]
THEN:
- AuthenticationService.createTokenFromSAML() is called with tenant context
- JWT is issued with claims:
  ```json
  {
    "sub": "user-uuid",
    "email": "user@gemeente-groningen.nl",
    "tenant_id": "tenant-A-uuid",
    "tenant_slug": "gemeente-groningen",
    "roles": ["case_handler"],
    "eherkenning_level": 3,
    "iat": ...,
    "exp": ...
  }
  ```
- JWT is signed with the Procest private key
- JWT is returned to browser; user is logged in with tenant context

### REQ-006-C: Cross-Tenant Token Rejection

GIVEN a user has valid JWT for tenant_id=A
WHEN they (or an attacker) attempt to use this JWT to access tenant_id=B resources
THEN:
- Request URL includes subdomain `tenantb.zaaksysteem.nl`
- TenantContextMiddleware extracts tenant_B from subdomain
- TenantIsolationMiddleware compares JWT tenant_id (A) with request tenant (B)
- Mismatch → request is rejected with HTTP 403 Forbidden
- Attempt is logged as security incident

### REQ-006-D: Mandate Matrix Validation on Every Action

GIVEN User-A is assigned eHerkenning role "Behandelaar"
WHEN they attempt to transition a case from "In behandeling" → "Beschikking opgesteld"
THEN:
- TenantAuthenticationService.validateMandateMatrix() checks:
  - Does User-A's eHerkenning role (Behandelaar) grant "case_status_update" permission?
  - Is the transition legal (no guards blocking)?
- If mandate permits, transition proceeds
- If mandate denies, error: "Your role does not have permission to update case status. Contact your administrator."

---

## REQ-007: Billing and Quota Enforcement

**Purpose**: Emit billing events and integrate with Shillinq for invoice generation.

### REQ-007-A: Billing Event Emission on Case Lifecycle

GIVEN a tenant has tier="pay_per_case" (hypothetical billing model)
WHEN a case is created successfully
THEN:
- TenantBillingService.emitEvent() is called:
  ```
  tenantRef=A, eventType="case_created", quantity=1, unitPrice=2.50, currency=EUR, occurredAt=now
  ```
- TenantBillingEvent record is inserted (invoiceRef=NULL initially)

GIVEN the same case is closed (status=Verleend, marked endDate=today)
WHEN the case-update job processes the closure
THEN:
- If tier includes pay-per-case charges for closures:
  ```
  tenantRef=A, eventType="case_closed", quantity=1, unitPrice=4.50, currency=EUR
  ```

GIVEN a case is deleted/withdrawn before closure
WHEN TenantBillingService.refund() is called
THEN:
- A billing event is emitted: `eventType="case_refund", quantity=-1, unitPrice=2.50`
- Netting against prior case_created charge on Shillinq invoice

### REQ-007-B: Daily Billing Export to Shillinq

GIVEN today is 2026-05-23, 02:00 UTC
WHEN the nightly job `export-billing-events` runs
THEN:
- All TenantBillingEvent records with invoiceRef=NULL are selected
- Records are grouped by tenant and month
- For each tenant-month group, Shillinq API is called:
  ```
  POST https://shillinq-api/v1/invoices/tenant-A/2026-05
  {
    "line_items": [
      {event_type: "case_created", qty: 45, unit_price: 2.50, ...},
      {event_type: "case_closed", qty: 40, unit_price: 4.50, ...},
      ...
    ]
  }
  ```
- Shillinq API returns `{invoice_id: "INV-2026-05-A-001", status: "draft"}`
- TenantBillingEvent records for this month-tenant are updated: `invoiceRef="INV-2026-05-A-001"`
- Job completes; next day Shillinq generates PDF and emails invoice to tenant

GIVEN Shillinq API is temporarily unavailable
WHEN export job runs
THEN:
- API call is retried up to 3 times with exponential backoff
- If still unavailable, job defers processing to next day
- Alert is sent to ops team

### REQ-007-C: Tenant Billing Dashboard

GIVEN tenant admin accesses Billing dashboard for Tenant A
WHEN they load `/dashboard/billing`
THEN:
- Page displays:
  1. **Current Month Summary**: Cases created (45), Closed (40), Refunds (-2), Total charged (€237.50)
  2. **YTD Breakdown**: Cases by month, with running total cost
  3. **Forecasted Spend**: If current trajectory continues, estimated charge by EOY
  4. **Invoice History**: List of months with PDF download links for issued invoices
  5. **Quota Status**: Current usage vs. limits (cases/month, storage, active users, API calls)

---

## REQ-008: Tenant Suspension and Termination

**Purpose**: Handle lifecycle events when tenants pause service or end contracts.

### REQ-008-A: Suspension Workflow

GIVEN tenant admin requests suspension (e.g., budget freeze, seasonal pause)
WHEN request is approved by SaaS provider
THEN:
- Tenant.status="suspended"
- All case creation API requests return HTTP 403 Forbidden: "Tenant is suspended. Contact support to reactivate."
- Existing cases remain visible to users
- No new TenantBillingEvents are emitted during suspension
- Webhook is sent to Shillinq: `{tenant_id: A, status: "suspended", effective_date: now}`

GIVEN tenant admin requests to reactivate from suspended state
WHEN request is approved
THEN:
- Tenant.status="active"
- Case creation is restored
- Billing resumes
- Reactivation event is sent to Shillinq

### REQ-008-B: Termination and Data Archival

GIVEN tenant contract ends and termination is initiated
WHEN TenantService.terminate(tenantId) is called with terminationReason
THEN:
- Tenant.status="terminated"
- Tenant.terminatedAt=now
- All API access is revoked (JWT validation rejects all requests from this tenant)
- Webhook to Shillinq: `{tenant_id: A, status: "terminated", final_invoice: {...}}`
- Data archival begins (depending on contract):
  - **Tier=basic/standard**: Tenant schema is retained in database for 1 year (offline backup), then deleted
  - **Tier=enterprise**: Tenant schema is exported to cold storage (S3 Glacier) per data residency rules, then deleted
  - All TenantBillingEvent records are finalized with invoiceRef (no pending charges)

GIVEN termination is complete
WHEN a user attempts to log in with this tenant's JWT
THEN:
- TenantContextMiddleware queries Tenant record by ID
- status="terminated" is detected
- Request is rejected: HTTP 403 Forbidden, "This tenant is no longer active."

---

## REQ-009: Multi-Tenant OpenRegister Integration

**Purpose**: Ensure OpenRegister supports per-tenant schema isolation.

### REQ-009-A: Tenant-Scoped Queries in OpenRegister

GIVEN OpenRegister is integrated with Procest via REST API
WHEN a request `GET /api/tenants/A/cases` arrives with JWT tenant_id=A
THEN:
- Procest TenantContextMiddleware sets PostgreSQL search_path for this connection
- OpenRegister queries are executed against the tenant-scoped schema
- Results include only cases from tenant A's schema
- No schema prefixing is required in queries; search_path ensures isolation

GIVEN a subsequent request arrives for tenant B
WHEN the connection pool provides a different PostgreSQL connection
THEN:
- search_path is set to tenant B's schema
- Queries return only tenant B data

### REQ-009-B: Tenant-Scoped Imports

GIVEN a tenant admin imports zaaktypes from an external XML/JSON source
WHEN the import API is called
THEN:
- OpenRegister creates caseType records in the requesting tenant's schema only
- No zaaktypes are created in other tenants' schemas
- Import history is logged per tenant

---

## REQ-010: Compliance and Auditing

**Purpose**: Maintain audit trails and meet regulatory requirements.

### REQ-010-A: Audit Logging for Data Access

GIVEN a case handler from Tenant A views case record
WHEN they load the case detail page
THEN:
- An auditTrail entry is recorded:
  ```json
  {
    action: "view",
    actor: "user-uuid",
    actorRole: "case_handler",
    resource: "case-xyz",
    resourceType: "case",
    timestamp: now,
    ipAddress: "...",
    userAgent: "..."
  }
  ```
- All auditTrail entries include tenant_id for later compliance audits

### REQ-010-B: BIO 2.0 Compliance for Enterprise Tier

GIVEN tenant has tier="enterprise"
WHEN any data modification occurs (create, update, delete)
THEN:
- auditTrail entry includes additional fields:
  - `deviceId` (if user has registered device)
  - `geoLocation` (approximate from IP)
  - `mfaVerified` (boolean, whether MFA was challenged)
  - `sessionDuration` (minutes user has been logged in)

GIVEN enterprise tenant is selected for quarterly pen-test
WHEN security team tests data isolation
THEN:
- Test scenarios verify no cross-tenant data leakage
- Test results are documented and provided to tenant
- Any findings trigger immediate remediation

### REQ-010-C: AVG Data Deletion on Termination

GIVEN tenant contract ends and termination is initiated
WHEN data retention period elapses (default 1 year)
THEN:
- Tenant schema is deleted from production database
- A deletion confirmation is logged in immutable audit store
- GDPR data deletion request is confirmed if tenant is subject
