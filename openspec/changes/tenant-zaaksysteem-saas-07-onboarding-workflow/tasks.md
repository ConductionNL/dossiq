# Tasks: tenant-zaaksysteem-saas-07-onboarding-workflow

Member 7 of 12 (code). Depends on member 06. Traces to giant Task 7 + Task 8 + Task 9 + REQ-003.

## 1. Onboarding checklist + dashboard

- [~] Implement `TenantOnboardingService.createOnboarding(tenantId)` (fork 7-step template, all pending) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `getProgress()` and `markStepComplete(step)` (timestamp + completedBy) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `TenantOnboardingController` (progress retrieval, step marking) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create the onboarding progress dashboard Vue component (i18n nl+en, modal-isolation) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Send the onboarding email with checklist link — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Decidesk contract integration

- [~] Implement `DecideskIntegrationService.initiateContractSignature()` (pre-filled redirect) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `handleContractSigned()` (set contractRef, complete contract step, notify) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `POST /webhooks/decidesk/contract-signed` with signature verification — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Handle Decidesk errors gracefully (timeout, rejection) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Go-live + tests

- [~] Implement `validateGoLive(tenantId)` (≥1 zaaktype, ≥1 mandaat, ≥1 tenant_admin) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] On pass: transition status → active, set activatedAt, trigger quota init (member 09) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add nightly reminder for unsigned contracts (14-day) + confirmation emails — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: checklist init, progress dashboard, admin email — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: Decidesk webhook updates contractRef (signature verified) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: go-live validation + activation transition — deferred to downstream cycle / fleet-wide adoption (handoff)
