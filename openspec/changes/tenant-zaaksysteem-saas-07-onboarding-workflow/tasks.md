# Tasks: tenant-zaaksysteem-saas-07-onboarding-workflow

Member 7 of 12 (code). Depends on member 06. Traces to giant Task 7 + Task 8 + Task 9 + REQ-003.

## 1. Onboarding checklist + dashboard

- [ ] Implement `TenantOnboardingService.createOnboarding(tenantId)` (fork 7-step template, all pending)
- [ ] Implement `getProgress()` and `markStepComplete(step)` (timestamp + completedBy)
- [ ] Implement `TenantOnboardingController` (progress retrieval, step marking)
- [ ] Create the onboarding progress dashboard Vue component (i18n nl+en, modal-isolation)
- [ ] Send the onboarding email with checklist link

## 2. Decidesk contract integration

- [ ] Implement `DecideskIntegrationService.initiateContractSignature()` (pre-filled redirect)
- [ ] Implement `handleContractSigned()` (set contractRef, complete contract step, notify)
- [ ] Implement `POST /webhooks/decidesk/contract-signed` with signature verification
- [ ] Handle Decidesk errors gracefully (timeout, rejection)

## 3. Go-live + tests

- [ ] Implement `validateGoLive(tenantId)` (≥1 zaaktype, ≥1 mandaat, ≥1 tenant_admin)
- [ ] On pass: transition status → active, set activatedAt, trigger quota init (member 09)
- [ ] Add nightly reminder for unsigned contracts (14-day) + confirmation emails
- [ ] Integration test: checklist init, progress dashboard, admin email
- [ ] Integration test: Decidesk webhook updates contractRef (signature verified)
- [ ] Integration test: go-live validation + activation transition
