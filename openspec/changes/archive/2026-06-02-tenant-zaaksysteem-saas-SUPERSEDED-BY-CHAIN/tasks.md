# Tasks: tenant-zaaksysteem-saas

Implementation tasks for multi-tenant SaaS enablement, covering tenant provisioning, data isolation, authentication, onboarding, configuration, quotas, and billing integration.

---

## 1. Core Tenant Infrastructure

### Task 1: Create Tenant Entity and CRUD API
**Spec ref**: REQ-001-A, REQ-001-B
**Files**:
- `lib/AppInfo/Migration/Version001CreateTenant.php` (database migration)
- `lib/Db/Tenant.php` (Doctrine entity)
- `lib/Db/TenantMapper.php` (data access)
- `lib/Service/TenantService.php`
- `lib/Controller/TenantController.php`

**Acceptance criteria**:
- GIVEN a new tenant is created WHEN THEN a Tenant record exists with slug, name, tier, status=onboarding
- Slug is auto-generated from name, lowercased, hyphens, unique
- CRUD endpoints return correct JSON with all tenant metadata
- List endpoint supports filtering by status (onboarding/active/suspended/terminated)

- [ ] Create database migration: Tenant table with id, slug, displayName, legalName, kvkNumber, contractRef, status, tier, isolationMode, dataResidency, createdAt, activatedAt, terminatedAt
- [ ] Implement TenantService: create(), getById(), listActive(), updateStatus()
- [ ] Implement TenantController: POST/GET/PATCH/DELETE endpoints
- [ ] Add slug generation function (lowercased, hyphens, max 64 chars)
- [ ] Test CRUD operations
- [ ] Test slug uniqueness constraint
- [ ] Add API documentation (OpenAPI 3.0)

### Task 2: Database Schema Provisioning (Schema-per-Tenant)
**Spec ref**: REQ-001-B, REQ-002-A
**Files**:
- `lib/Service/TenantProvisioningService.php`
- `lib/Migration/TenantSchemaProvisioner.php`
- `lib/Settings/tenant-default-templates.json` (seed data)

**Acceptance criteria**:
- GIVEN a tenant is created WHEN provisioning is called THEN a PostgreSQL schema is created with all tables cloned
- Schema name format: `tenant_{uuid}_{slug}`
- Standard zaaktype templates are seeded into tenant schema
- Default roles (tenant_admin, case_handler, viewer) are created
- Welcome email is sent to tenant admin

- [ ] Implement TenantProvisioningService.provision(tenantId)
- [ ] Create schema cloning logic (copy all table structures and constraints from public)
- [ ] Seed default zaaktype templates into tenant schema
- [ ] Create default roles and roleType records in tenant schema
- [ ] Implement sendWelcomeEmail() (to be configured with email provider)
- [ ] Add error handling for schema creation failures (rollback on error)
- [ ] Test provisioning workflow end-to-end
- [ ] Test schema isolation (SELECT FROM case only returns tenant-specific rows)

### Task 3: Tenant Context Middleware (search_path)
**Spec ref**: REQ-002-A, REQ-002-B
**Files**:
- `lib/Middleware/TenantContextMiddleware.php`
- `lib/Middleware/TenantIsolationMiddleware.php`
- `lib/Db/TenantContext.php` (request-scoped singleton)

**Acceptance criteria**:
- GIVEN a request with tenant_id in JWT WHEN middleware runs THEN search_path is set to 'public,tenant_X_schema'
- All Eloquent queries are executed within the tenant schema context
- Cross-tenant query attempts return 404 (not found)
- Database connection is maintained per-request

- [ ] Create TenantContextMiddleware to extract tenant_id from JWT and resolve Tenant record
- [ ] Create TenantIsolationMiddleware to set PostgreSQL search_path per request
- [ ] Implement TenantContext service to store request-scoped tenant data (tenant_id, schema name, tenant object)
- [ ] Register middleware in app/Http/Kernel.php
- [ ] Test search_path is correctly set before Eloquent queries
- [ ] Test that Eloquent models query only tenant schema
- [ ] Test cross-tenant query (should return 0 rows, not error)
- [ ] Benchmark query performance with search_path overhead

---

## 2. Authentication and Tenant Validation

### Task 4: JWT Tenant Claim Injection
**Spec ref**: REQ-006-A, REQ-006-B
**Files**:
- `lib/Service/AuthenticationService.php` (enhance existing)
- `lib/Middleware/AuthenticateMiddleware.php` (enhance existing)

**Acceptance criteria**:
- GIVEN a user logs in via eHerkenning WHEN JWT is issued THEN tenant_id is included in claims
- Subsequent API requests use the tenant_id claim to set context
- Users can only access resources within their tenant

- [ ] Enhance AuthenticationService to accept tenant context during login
- [ ] Inject tenant_id, tenant_slug into JWT payload during token creation
- [ ] Update AuthenticateMiddleware to validate JWT signature and extract tenant_id
- [ ] Ensure all downstream middleware receives tenant_id from JWT
- [ ] Test JWT creation with tenant claims
- [ ] Test JWT validation with tenant claims
- [ ] Test cross-tenant token rejection (403 Forbidden when accessing different tenant's resources)

### Task 5: Tenant Claim Validation Middleware
**Spec ref**: REQ-002-B, REQ-002-C
**Files**:
- `lib/Middleware/TenantClaimValidationMiddleware.php`

**Acceptance criteria**:
- GIVEN a JWT with tenant_id=A WHEN request is to tenant_id=B resource THEN 403 Forbidden is returned
- Cross-tenant access attempts are logged as security incidents
- After 5 failed attempts in 1 hour from same IP, alert is sent

- [ ] Create middleware to compare JWT tenant_id with request tenant_id (extracted from URL/domain/header)
- [ ] Implement mismatch detection and 403 response
- [ ] Add security logging for cross-tenant attempts (IP, timestamp, attempted tenant_id, user)
- [ ] Implement rate limiting logic (track failed attempts per IP)
- [ ] Add alert mechanism for security team (email/Slack/etc.)
- [ ] Test valid same-tenant request succeeds
- [ ] Test cross-tenant request is blocked
- [ ] Test rate limiting after N failed attempts

### Task 6: Mandate Matrix Validation
**Spec ref**: REQ-002-D, REQ-006-D
**Files**:
- `lib/Service/TenantAuthenticationService.php`
- `lib/Middleware/MandateValidationMiddleware.php`

**Acceptance criteria**:
- GIVEN a user with eHerkenning role Behandelaar WHEN they attempt case_edit action THEN mandaat-matrix is checked
- Permission is granted/denied based on mandate; result is logged

- [ ] Implement TenantAuthenticationService.validateMandateMatrix(tenant_id, user_id, action)
- [ ] Load TenantMandate record for tenant; check user's role against mandate matrix
- [ ] Return {allowed: true/false, reason: string}
- [ ] Create MandateValidationMiddleware to call validation on requests that require mandate checks
- [ ] Add logging of all mandate validation results (audit trail)
- [ ] Test with different roles (Behandelaar, Vergunningverlener, etc.)
- [ ] Test with different actions (case_create, case_edit, case_delete, etc.)

---

## 3. Tenant Onboarding

### Task 7: Tenant Onboarding Workflow Setup
**Spec ref**: REQ-003-A, REQ-003-D
**Files**:
- `lib/Migration/CreateTenantOnboardingTask.php`
- `lib/Db/TenantOnboardingTask.php`
- `lib/Service/TenantOnboardingService.php`
- `lib/Controller/TenantOnboardingController.php`
- `resources/views/tenants/onboarding/progress-dashboard.vue`

**Acceptance criteria**:
- GIVEN a new tenant WHEN onboarding is initialized THEN checklist with 7 steps is created
- Admin sees progress dashboard with current step highlighted
- Step completion is tracked with timestamp and completed-by user

- [ ] Create TenantOnboardingTask table/entity/mapper
- [ ] Implement TenantOnboardingService.createOnboarding(tenantId)
- [ ] Implement TenantOnboardingController for progress retrieval and step marking
- [ ] Create onboarding progress dashboard Vue component
- [ ] Test checklist initialization with all 7 steps (pending status)
- [ ] Test progress dashboard displays correct step status
- [ ] Test admin receives onboarding email with checklist link

### Task 8: Decidesk Contract Integration
**Spec ref**: REQ-003-B
**Files**:
- `lib/Service/DecideskIntegrationService.php`
- `lib/Controller/WebhookController.php` (add Decidesk webhook handler)

**Acceptance criteria**:
- GIVEN tenant admin clicks "Sign contract" WHEN they complete e-signature in Decidesk THEN Procest receives webhook
- Tenant.contractRef is set; "contract" step is marked completed

- [ ] Implement DecideskIntegrationService with methods: initiateContractSignature(tenantId), handleContractSigned(tenantId, decidesk_contract_id)
- [ ] Redirect tenant admin to Decidesk with pre-filled tenant details
- [ ] Implement webhook endpoint POST /webhooks/decidesk/contract-signed
- [ ] Update Tenant.contractRef and TenantOnboardingTask on success
- [ ] Send confirmation email to tenant admin
- [ ] Test contract signature workflow
- [ ] Test webhook receipt and data update
- [ ] Handle Decidesk errors gracefully (timeout, signature rejection, etc.)

### Task 9: Go-Live Validation and Tenant Activation
**Spec ref**: REQ-003-C
**Files**:
- `lib/Service/TenantOnboardingService.php` (extend with validateGoLive)
- `lib/Controller/TenantOnboardingController.php` (add goLive endpoint)

**Acceptance criteria**:
- GIVEN tenant has ≥1 zaaktype, ≥1 mandaat, ≥1 user WHEN go-live is requested THEN tenant.status="active"
- Tenant.activatedAt is set; billing quotas are initialized; all users gain access

- [ ] Implement TenantOnboardingService.validateGoLive(tenantId)
- [ ] Check: ≥1 zaaktype (not draft), ≥1 mandaat record, ≥1 TenantUser with role tenant_admin
- [ ] If all valid, transition tenant.status = onboarding → active
- [ ] Set tenant.activatedAt = now
- [ ] Initialize TenantQuota records (to be done in Task 12)
- [ ] Send confirmation email: "Tenant [name] is now live"
- [ ] Test validation with missing prerequisites (error message lists missing items)
- [ ] Test successful activation and status transition

---

## 4. Tenant Configuration

### Task 10: Tenant Configuration Storage and API
**Spec ref**: REQ-004-A, REQ-004-C, REQ-004-D
**Files**:
- `lib/Migration/CreateTenantConfiguration.php`
- `lib/Db/TenantConfiguration.php`
- `lib/Service/TenantConfigurationService.php`
- `lib/Controller/TenantConfigurationController.php`

**Acceptance criteria**:
- GIVEN tenant admin updates branding (logo, colors) WHEN saved THEN TenantConfiguration is persisted
- Retrieve API returns correct branding, locale, features

- [ ] Create TenantConfiguration table: tenantRef, branding (JSON), domain, locale, timezone, dateFormat, currency, features (JSON array)
- [ ] Implement TenantConfigurationService: getConfig(), updateBranding(), updateLocale(), setFeatureFlag()
- [ ] Implement TenantConfigurationController: GET, PATCH endpoints
- [ ] Add logo upload handler (store in Nextcloud, generate URL)
- [ ] Add color validation (hex colors)
- [ ] Add locale/timezone validation (against known list)
- [ ] Test CRUD operations for configuration
- [ ] Test logo upload and URL generation
- [ ] Test feature flag storage and retrieval

### Task 11: Branding and Theming (CSS Variables)
**Spec ref**: REQ-004-A, REQ-004-B
**Files**:
- `lib/Controller/BrandingController.php`
- `resources/views/shared/theming-tokens.vue`
- `resources/css/tenant-theming.css` (template)

**Acceptance criteria**:
- GIVEN branding is configured WHEN frontend requests theming tokens THEN CSS variables are returned
- NL Design System components automatically use tenant colors

- [ ] Create BrandingController.getThemingTokens(tenantId)
- [ ] Generate CSS variable map from TenantConfiguration branding: --tenant-primary, --tenant-secondary, --tenant-logo-url, --tenant-font-family
- [ ] Implement frontend endpoint GET /api/tenants/{tenantId}/config/theming-tokens
- [ ] Frontend injects tokens into <head> as <style> tag
- [ ] Update NL Design System component imports to use CSS variables
- [ ] Test theming tokens API response
- [ ] Test CSS variables are injected on page load
- [ ] Test NL Design System components respect variables (manual testing in browser)

### Task 12: Domain Provisioning (Let's Encrypt, ACME)
**Spec ref**: REQ-004-B
**Files**:
- `lib/Service/DomainProvisioningService.php`
- `lib/Controller/DomainController.php`

**Acceptance criteria**:
- GIVEN tenant admin enters domain "groningen.zaaksysteem.nl" WHEN provisioning is requested THEN ACME challenge is sent
- Let's Encrypt cert is issued; domain is live

- [ ] Implement DomainProvisioningService: validateDomain(), requestACME(), installCertificate()
- [ ] DNS validation via ACME challenges (DNS TXT record)
- [ ] Let's Encrypt API integration
- [ ] Certificate installation in load balancer/reverse proxy (implementation depends on infra)
- [ ] Domain ownership validation and error handling
- [ ] Test domain provisioning workflow (may be manual without DNS access)
- [ ] Add error handling for invalid domains, ACME failures, etc.

---

## 5. Quotas and Enforcement

### Task 13: Tenant Quota Schema and Service
**Spec ref**: REQ-005-A, REQ-005-B, REQ-005-C
**Files**:
- `lib/Migration/CreateTenantQuota.php`
- `lib/Db/TenantQuota.php`
- `lib/Service/TenantQuotaService.php`
- `lib/Controller/TenantQuotaController.php`

**Acceptance criteria**:
- GIVEN a tenant is created WHEN quotas are initialized THEN TenantQuota records exist for cases/month, storage, users, API calls
- Limits vary by tier (basic=100, standard=1000, enterprise=unlimited)

- [ ] Create TenantQuota table: tenantRef, quotaType enum, limit, currentUsage, resetAt, softLimitWarningPercent, enforcement enum
- [ ] Implement TenantQuotaService: initialize(tenantId, tier), getQuota(), checkLimit(), increment()
- [ ] Define quota limits per tier:
  - basic: cases_per_month=100, storage_gb=10, active_users=5, api_calls_per_hour=1000
  - standard: cases_per_month=1000, storage_gb=100, active_users=50, api_calls_per_hour=10000
  - enterprise: all unlimited
- [ ] Implement TenantQuotaController: GET list, PATCH limits (for admin)
- [ ] Add quota initialization on tenant activation (go-live)
- [ ] Test quota retrieval by type
- [ ] Test limits variation by tier

### Task 14: Quota Enforcement Middleware
**Spec ref**: REQ-005-B, REQ-005-C
**Files**:
- `lib/Middleware/QuotaEnforcementMiddleware.php`

**Acceptance criteria**:
- GIVEN a case creation request WHEN quota check runs THEN request is allowed if within limit, blocked if exceeded
- Soft limit warnings are sent at 80% usage

- [ ] Create QuotaEnforcementMiddleware
- [ ] Implement checkQuota(quotaType, amount) → {allowed, remaining}
- [ ] On case creation: check cases_per_month quota
- [ ] On API call: check api_calls_per_hour quota
- [ ] If enforcement="block": return HTTP 429 Too Many Requests
- [ ] If enforcement="throttle": rate-limit requests (queue, delay, or reject)
- [ ] If enforcement="warn": log warning, allow request
- [ ] On soft limit (80%): send email to tenant admin
- [ ] Test quota enforcement with different enforcement types
- [ ] Test rate limiting under load

### Task 15: Monthly Quota Reset Job
**Spec ref**: REQ-005-D
**Files**:
- `lib/Jobs/ResetMonthlyQuotas.php` (Nextcloud background job)

**Acceptance criteria**:
- GIVEN today is 1st of month WHEN quota-reset job runs THEN currentUsage is reset to 0, resetAt is updated
- Job runs daily; only resets quotas where resetAt < today

- [ ] Create ResetMonthlyQuotas job (Nextcloud OCP\BackgroundJob\Job)
- [ ] Query all TenantQuota with quotaType in ['cases_per_month', 'api_calls_per_hour'] and resetAt < today
- [ ] Set currentUsage = 0
- [ ] Update resetAt = next month 1st
- [ ] Register job in appinfo/info.xml (daily execution)
- [ ] Test job execution (mock current date)
- [ ] Test quota reset for multiple tenants
- [ ] Test quotas are not reset if resetAt is in future

---

## 6. Billing

### Task 16: Tenant Billing Event Storage and Service
**Spec ref**: REQ-007-A
**Files**:
- `lib/Migration/CreateTenantBillingEvent.php`
- `lib/Db/TenantBillingEvent.php`
- `lib/Service/TenantBillingService.php`

**Acceptance criteria**:
- GIVEN a case is created WHEN billing event is emitted THEN TenantBillingEvent record is created
- Event includes: tenantRef, eventType, quantity, unitPrice, occurredAt, invoiceRef (initially NULL)

- [ ] Create TenantBillingEvent table: id, tenantRef, eventType enum (case_created, case_closed, user_activated, etc.), quantity, unitPrice, currency, occurredAt, invoiceRef
- [ ] Implement TenantBillingService: emitEvent(), getMonthBilling()
- [ ] Emit case_created event when case is created
- [ ] Emit case_closed event when case is marked closed (status final)
- [ ] Emit user_activated on first user login each month
- [ ] Emit refund event if case is withdrawn/deleted
- [ ] Test event emission with sample data
- [ ] Test event retrieval by month-tenant

### Task 17: Shillinq Billing Export Job
**Spec ref**: REQ-007-B
**Files**:
- `lib/Jobs/ExportBillingToShillinq.php`
- `lib/Service/ShillinqIntegrationService.php`

**Acceptance criteria**:
- GIVEN pending TenantBillingEvents exist WHEN export job runs THEN Shillinq API is called with aggregated events
- On success, invoiceRef is set; on failure, job is deferred to next day

- [ ] Create ExportBillingToShillinq job
- [ ] Query TenantBillingEvent records with invoiceRef=NULL
- [ ] Group by tenant and month
- [ ] Call Shillinq API for each tenant-month (POST /v1/invoices/tenant-X/YYYY-MM)
- [ ] Include line_items array with event details
- [ ] Handle Shillinq API errors (retry 3x, exponential backoff, defer on final failure)
- [ ] On success, update invoiceRef in TenantBillingEvent
- [ ] Register job in appinfo/info.xml (daily at 02:00 UTC)
- [ ] Test job with mock Shillinq API
- [ ] Test event grouping and aggregation
- [ ] Test API error handling and retry logic
- [ ] Test alert mechanism on repeated failures

### Task 18: Tenant Billing Dashboard
**Spec ref**: REQ-007-C
**Files**:
- `lib/Controller/TenantBillingController.php`
- `resources/views/tenants/billing/dashboard.vue`
- `lib/Service/BillingDashboardService.php` (aggregate billing data)

**Acceptance criteria**:
- GIVEN tenant admin accesses billing dashboard WHEN page loads THEN current month summary, YTD, forecasts, and invoice history are displayed

- [ ] Create TenantBillingController: dashboard(), events(), export()
- [ ] Implement BillingDashboardService.getMonthSummary(), getYTDBreakdown(), getForecast()
- [ ] Aggregate TenantBillingEvent records by month
- [ ] Calculate YTD total spend
- [ ] Forecast EOY spend based on current trajectory
- [ ] Fetch Shillinq invoice links (by invoiceRef)
- [ ] Create Vue dashboard component with:
  - Current month summary (cases, refunds, total)
  - YTD breakdown chart
  - Forecasted spend
  - Invoice history with download links
  - Quota status
- [ ] Test dashboard data aggregation
- [ ] Test forecasting calculation
- [ ] Test invoice link generation

---

## 7. Tenant Lifecycle

### Task 19: Tenant Suspension and Reactivation
**Spec ref**: REQ-008-A
**Files**:
- `lib/Service/TenantService.php` (extend with suspend/reactivate)
- `lib/Controller/TenantController.php` (add endpoints)

**Acceptance criteria**:
- GIVEN tenant admin requests suspension WHEN approved THEN tenant.status="suspended"; API calls return 403
- Webhook is sent to Shillinq; billing pauses

- [ ] Add TenantService.suspend(tenantId, reason)
- [ ] Set tenant.status="suspended"
- [ ] Send webhook to Shillinq: {tenant_id, status: "suspended"}
- [ ] Add middleware check: if tenant.status="suspended", return 403 "Tenant is suspended"
- [ ] Add TenantService.reactivate(tenantId)
- [ ] Set tenant.status="active"
- [ ] Resume billing (no additional setup needed; quotas resume incrementing)
- [ ] Test suspension workflow
- [ ] Test API rejection when suspended
- [ ] Test reactivation and resume

### Task 20: Tenant Termination and Data Archival
**Spec ref**: REQ-008-B
**Files**:
- `lib/Service/TenantService.php` (extend with terminate)
- `lib/Jobs/ArchiveTenantData.php`

**Acceptance criteria**:
- GIVEN tenant contract ends WHEN terminate() is called THEN tenant.status="terminated"; schema is archived; API access revoked
- After retention period (1 year), schema is deleted

- [ ] Add TenantService.terminate(tenantId, reason, retentionYears=1)
- [ ] Set tenant.status="terminated", tenant.terminatedAt=now
- [ ] Finalize all pending billing (ensure all TenantBillingEvents have invoiceRef)
- [ ] Send termination webhook to Shillinq
- [ ] Create async archival job (depends on data volume):
  - For basic/standard: retain schema for retentionYears (offline)
  - For enterprise: export to cold storage (S3 Glacier) per dataResidency
  - After retention period, delete schema from database
- [ ] Implement ArchiveTenantData job
- [ ] Middleware check: if tenant.status="terminated", return 403 "Tenant no longer active"
- [ ] Test termination workflow
- [ ] Test API rejection after termination
- [ ] Test schema archival (mock archival destination)

---

## 8. Testing & Documentation

### Task 21: Integration Tests for Tenant Isolation
**Spec ref**: REQ-002-A, REQ-002-B, REQ-002-C
**Files**:
- `tests/Integration/TenantIsolationTest.php`

**Acceptance criteria**:
- Tests verify no cross-tenant data leakage
- Tests validate JWT tenant claim checking
- Tests confirm search_path isolation

- [ ] Write test: create two tenants with overlapping case IDs, verify isolation
- [ ] Write test: attempt cross-tenant query, verify 404 response
- [ ] Write test: manipulated filter WHERE tenant_id='other', verify isolation
- [ ] Write test: JWT token swap (A's token on B's domain), verify rejection
- [ ] Write test: audit logging of cross-tenant access attempts
- [ ] Run tests and ensure 100% pass
- [ ] Add tests to CI pipeline

### Task 22: End-to-End Onboarding Flow Tests
**Spec ref**: REQ-003, all onboarding tasks
**Files**:
- `tests/Feature/TenantOnboardingFlowTest.php`

**Acceptance criteria**:
- Full onboarding scenario: create tenant → sign contract → configure mandate → configure SSO → set branding → select zaaktype → create user → go live

- [ ] Write E2E test: create tenant via API
- [ ] Sign contract via Decidesk webhook simulation
- [ ] Import mandate CSV
- [ ] Configure SSO endpoint
- [ ] Upload logo and colors
- [ ] Select zaaktype from templates
- [ ] Create first user
- [ ] Request go-live
- [ ] Verify tenant.status="active"
- [ ] Run test end-to-end
- [ ] Document test steps for manual QA

### Task 23: API Documentation and OpenAPI Spec
**Spec ref**: All API endpoints
**Files**:
- `openapi/schemas/tenant-api.yaml`

**Acceptance criteria**:
- All tenant endpoints documented in OpenAPI 3.0
- Examples provided for request/response bodies
- Authentication and tenant context requirements documented

- [ ] Document all Tenant* endpoints (CRUD, onboarding, config, quotas, billing)
- [ ] Include request/response examples
- [ ] Document error responses (401, 403, 404, 429, etc.)
- [ ] Document authentication (JWT, tenant claim)
- [ ] Document webhook endpoints (Decidesk, etc.)
- [ ] Generate API docs from OpenAPI spec
- [ ] Publish to API documentation portal

---

## 9. Cleanup and Hardening

### Task 24: Security Hardening Checklist
**Spec ref**: REQ-002, REQ-010
**Files**: (code review checklist)

**Acceptance criteria**:
- All database queries are tenant-scoped
- All API endpoints validate tenant claim
- Audit trails are comprehensive
- Secrets (database credentials for enterprise DB-per-tenant) are securely stored

- [ ] Code review: all Eloquent queries include tenant context (either via search_path or explicit WHERE clause)
- [ ] Code review: all API endpoints validate JWT tenant claim
- [ ] Code review: all mutations are audit-logged
- [ ] Code review: no hardcoded secrets in code
- [ ] Code review: error messages don't leak tenant info
- [ ] Security testing: pen-test data isolation (attempted JWT forgery, cross-tenant queries)
- [ ] Dependency audit: check all dependencies for security vulnerabilities
- [ ] Performance audit: query performance with search_path overhead

### Task 25: Performance Optimization
**Spec ref**: General (Phase 2+)
**Files**: (benchmarking and tuning)

**Acceptance criteria**:
- Query performance is acceptable with 50+ tenants on single database
- API response times < 200ms p99 for case operations

- [ ] Benchmark current query performance with multiple tenant schemas
- [ ] Add database indexes as needed (tenant_id on shared tables, if any)
- [ ] Profile slow queries and optimize
- [ ] Load test: simulate 50+ concurrent users across multiple tenants
- [ ] Monitor performance metrics (p95, p99 latencies)
- [ ] Document scaling limits and recommendations

---

## Implementation Order

Phase 1 (MVP):
1. Task 1-3: Core tenant infrastructure
2. Task 4-6: Authentication
3. Task 7-9: Onboarding (contract, go-live)
4. Task 10-11: Configuration and branding
5. Task 13-15: Quotas and enforcement
6. Task 16-18: Billing (events, export, dashboard)
7. Task 19: Suspension/reactivation
8. Task 21-23: Testing and documentation

Phase 2 (Enterprise):
9. Task 12: Domain provisioning (Let's Encrypt)
10. Task 20: Termination and archival
11. Task 24-25: Security and performance hardening

Notes:
- Decidesk integration (Task 8) requires Decidesk API access and webhook configuration
- Shillinq integration (Task 17) requires Shillinq API credentials and webhook setup
- Domain provisioning (Task 12) requires DNS admin access and Let's Encrypt account
- Database-per-tenant mode is Phase 2 enhancement (Task 1 focuses on schema-per-tenant)
