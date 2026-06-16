---
status: draft
---

# Tenant quotas and enforcement — Specification Delta

## ADDED Requirements

### Requirement: Tier-based quota initialisation (REQ-005-A, REQ-005-E)

The system SHALL initialise per-tenant quotas from tier templates and apply tier changes within one minute.

#### Scenario: Quotas initialised by tier

- **GIVEN** a tenant with tier="basic" is activated
- **WHEN** `TenantQuotaService.initialize(tenantId)` runs
- **THEN** `TenantQuota` rows SHALL be created (cases_per_month=100 block, storage_gb=10 warn, active_users=5 warn, api_calls_per_hour=1000 throttle)
- **AND** for tier="standard" the limits SHALL be 1000/100/50/10000, and for "enterprise" unlimited (NULL)
- **AND** `resetAt` SHALL be the first day of next month

#### Scenario: Tier upgrade takes effect immediately

- **GIVEN** a tenant on basic (100/month) upgrades to standard (1000/month)
- **WHEN** the upgrade is processed
- **THEN** the cases_per_month limit SHALL become 1000 and enforcement SHALL use it within 1 minute

### Requirement: Real-time quota enforcement (REQ-005-B, REQ-005-C)

The system SHALL enforce quotas atomically per request with warn/throttle/block modes and soft-limit warnings.

#### Scenario: Block at limit

- **GIVEN** a tenant has used 100 of 100 cases_per_month (enforcement="block")
- **WHEN** they attempt to create case #101
- **THEN** the request SHALL return HTTP 429 with `{error, quota_type, limit, current_usage}`
- **AND** a `quota_exceeded` billing event SHALL be emitted
- **AND** the tenant admin SHALL receive an upgrade-prompt email

#### Scenario: Soft-limit warning at 80%

- **GIVEN** softLimitWarningPercent=80 on a 100-case quota
- **WHEN** the tenant reaches 80 cases
- **THEN** a soft-limit warning email SHALL be sent to the tenant admin

### Requirement: Monthly quota reset (REQ-005-D)

The system SHALL reset monthly quota usage on a daily background job for quotas whose reset time has passed.

#### Scenario: Reset only due quotas

- **GIVEN** the daily reset job runs
- **WHEN** it processes monthly-reset quotas
- **THEN** quotas with `resetAt < today` SHALL have `currentUsage` reset to 0 and `resetAt` advanced to next month
- **AND** quotas with a future `resetAt` SHALL be left unchanged
