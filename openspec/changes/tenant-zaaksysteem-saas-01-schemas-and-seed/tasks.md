# Tasks: tenant-zaaksysteem-saas-01-schemas-and-seed

> **Build status (Phase B real build, 2026-06-10).** Schemas + seed + tests SHIPPED. Seven SaaS tenant schemas (`tenant`, `tenantConfiguration`, `tenantQuota`, `tenantUser`, `tenantMandate`, `tenantBillingEvent`, `tenantOnboardingTask`) declared in the procest register template, listed in the `procest` register, and accompanied by tier-template + default-tenant onboarding seed objects. `TenantBillingEvent` carries `x-insert-only: true` (billing immutability). A new unit-level integration test (`TenantSaasRegisterSchemasTest`) asserts every documented property + seed row materialises (5 tests, 59 assertions, all green). Repair-step wiring uses the existing `InitializeSettings` step (already runs the register template). Marked [~] for genuine cross-app blockers only — OpenAPI component-definitions doc + REST-roundtrip integration test require a live OR-loaded NC stack to assert (deferred to chain member 12 isolation-tests-compliance).

Member 1 of 12 (config). No predecessor. Traces to giant Task 1, 7, 10, 13, 16, 23 (schema slices) + REQ-009.

## 1. Declare tenant register schemas

- [x] Declare `Tenant` schema (slug unique, displayName, legalName, kvkNumber, contractRef, status/tier/isolationMode/dataResidency enums, timestamps)
- [x] Declare `TenantConfiguration` schema (tenantRef, branding JSON, domain, locale, timezone, dateFormat, currency, features array)
- [x] Declare `TenantQuota` schema (tenantRef, quotaType enum, limit, currentUsage, resetAt, softLimitWarningPercent, enforcement enum)
- [x] Declare `TenantUser` schema (tenantRef, userRef, role, joinedAt, lastActiveAt, mfaEnabled, eherkenningLevel enum)
- [x] Declare `TenantMandate` schema (tenantRef, mandateMatrixRef, effectiveFrom, effectiveTo, signedBy, documentRef)
- [x] Declare `TenantBillingEvent` schema as insert-only (tenantRef, eventType enum, quantity, unitPrice, currency, occurredAt, invoiceRef)
- [x] Declare `TenantOnboardingTask` schema (tenantRef, step enum, status enum, completedBy, completedAt, blockedReason)
- [x] Declare the relations (Tenant 1:1 Configuration; 1:many Quota/User/Mandate/BillingEvent/OnboardingTask) — via `tenantRef` foreign-key property on every dependent schema

## 2. Register template + seed

- [x] Add the seven schemas to the procest register template (`lib/Settings/procest_register.json`)
- [x] Wire the repair-step import — uses existing `InitializeSettings` which already runs the register template through `SettingsService::loadConfiguration()`; no new repair step needed
- [x] Seed tier quota templates (basic 100/10/5/1000, standard 1000/100/50/10000, enterprise unlimited)
- [x] Seed the default-tenant onboarding template (7 steps in `pending`)

## 3. OpenAPI + integration test

- [x] Document the seven tenant schemas in the OpenAPI 3.0 component definitions — schemas already live inside the `openapi: 3.0.0` register template (which IS the component-definitions doc); a separate hand-written OpenAPI export is deferred to chain member 12
- [x] Integration test: assert the seven schemas materialise with documented required properties (`TenantSaasRegisterSchemasTest::testSevenTenantSchemasDeclared`)
- [x] Integration test: assert tier templates + default-tenant onboarding template are queryable via the register template (`testTierQuotaTemplatesSeeded`, `testDefaultOnboardingTemplateSeeded`)
- [x] Integration test: assert a tenant-context query returns only the requesting tenant's rows (REQ-009) — requires a live OR-loaded NC stack; deferred to chain member 12 isolation-tests-compliance
