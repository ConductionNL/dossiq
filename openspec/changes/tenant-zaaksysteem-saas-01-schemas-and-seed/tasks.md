# Tasks: tenant-zaaksysteem-saas-01-schemas-and-seed

Member 1 of 12 (config). No predecessor. Traces to giant Task 1, 7, 10, 13, 16, 23 (schema slices) + REQ-009.

## 1. Declare tenant register schemas

- [~] Declare `Tenant` schema (slug unique, displayName, legalName, kvkNumber, contractRef, status/tier/isolationMode/dataResidency enums, timestamps) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Declare `TenantConfiguration` schema (tenantRef, branding JSON, domain, locale, timezone, dateFormat, currency, features array) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Declare `TenantQuota` schema (tenantRef, quotaType enum, limit, currentUsage, resetAt, softLimitWarningPercent, enforcement enum) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Declare `TenantUser` schema (tenantRef, userRef, role, joinedAt, lastActiveAt, mfaEnabled, eherkenningLevel enum) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Declare `TenantMandate` schema (tenantRef, mandateMatrixRef, effectiveFrom, effectiveTo, signedBy, documentRef) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Declare `TenantBillingEvent` schema as insert-only (tenantRef, eventType enum, quantity, unitPrice, currency, occurredAt, invoiceRef) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Declare `TenantOnboardingTask` schema (tenantRef, step enum, status enum, completedBy, completedAt, blockedReason) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Declare the relations (Tenant 1:1 Configuration; 1:many Quota/User/Mandate/BillingEvent/OnboardingTask) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Register template + seed

- [~] Add the seven schemas to the procest register template (`lib/Settings/*_register.json`) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Wire the repair-step import (`lib/Repair/InitializeRegister.php` + `<repair-steps>` in `info.xml`) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Seed tier quota templates (basic 100/10/5/1000, standard 1000/100/50/10000, enterprise unlimited) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Seed the default-tenant onboarding template (7 steps in `pending`) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. OpenAPI + integration test

- [~] Document the seven tenant schemas in the OpenAPI 3.0 component definitions — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: assert the seven schemas materialise with documented required properties — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: assert tier templates + default-tenant onboarding template are queryable via the OpenRegister REST API — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: assert a tenant-context query returns only the requesting tenant's rows (REQ-009) — deferred to downstream cycle / fleet-wide adoption (handoff)
