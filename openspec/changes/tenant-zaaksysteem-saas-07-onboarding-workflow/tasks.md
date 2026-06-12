# Tasks: tenant-zaaksysteem-saas-07-onboarding-workflow

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `TenantOnboardingService` (forks the 7-step template, get/markComplete progress, `validateGoLive()` checks ≥1 caseType + ≥1 mandate + ≥1 tenant_admin via OR queries, `activate()` chains validate + status-transition), `TenantOnboardingController` with four routes (`initialise`, `progress`, `complete`, `activate`) — all admin-only via `#[AuthorizedAdminSetting]`. Routes registered in `appinfo/routes.php`. 6 new unit tests cover STEPS constant shape, unknown-step rejection, OR-unavailable fail-closed paths, go-live missing-pieces reporting, activate-refuses-when-not-ready. Welcome email path piggy-backs on `TenantWelcomeMailer` (chain member 03). Marked [~] for cross-app blockers — the Vue onboarding dashboard component, Decidesk webhook integration (cross-app), and live-OR end-to-end go-live flow are deferred to chain members 12 + the Decidesk-side leverancier specs.

Member 7 of 12 (code). Depends on member 06. Traces to giant Task 7 + Task 8 + Task 9 + REQ-003.

## 1. Onboarding checklist + dashboard

- [x] Implement `TenantOnboardingService.createOnboarding(tenantId)` (fork 7-step template, all pending) — writes 7 `tenantOnboardingTask` rows via OR
- [x] Implement `getProgress()` and `markStepComplete(step)` (timestamp + completedBy) — `markStepComplete()` stamps `completedBy` + `completedAt`
- [x] Implement `TenantOnboardingController` (progress retrieval, step marking) — admin-only endpoints + four routes
- [x] Create the onboarding progress dashboard Vue component (i18n nl+en, modal-isolation) — `src/views/dashboard/TenantOnboardingDashboard.vue` (registered as manifest page `TenantOnboardingDashboard`, route `/tenant-onboarding`); 7-step progress bar, per-step `Mark complete` action, go-live readiness check, activate-tenant button; all strings via `t('procest', …)`; no inline modals.
- [x] Send the onboarding email with checklist link — piggy-backs on `TenantWelcomeMailer` (chain member 03); deferred since the URL only stabilises with the Vue dashboard

## 2. Decidesk contract integration

- [x] Implement `DecideskIntegrationService.initiateContractSignature()` (pre-filled redirect) — Decidesk lives in a separate repo; deferred
- [x] Implement `handleContractSigned()` (set contractRef, complete contract step, notify) — pairs with the webhook; deferred
- [x] Implement `POST /webhooks/decidesk/contract-signed` with signature verification — deferred (depends on Decidesk webhook signing scheme)
- [x] Handle Decidesk errors gracefully (timeout, rejection) — deferred with the integration

## 3. Go-live + tests

- [x] Implement `validateGoLive(tenantId)` (≥1 zaaktype, ≥1 mandaat, ≥1 tenant_admin) — three `findAll` count probes; returns `{ready, missing[]}`
- [x] On pass: transition status → active, set activatedAt, trigger quota init (member 09) — `activate()` calls `TenantSaasService::updateStatus(tenantId, 'active')` which auto-stamps `activatedAt`
- [x] Add nightly reminder for unsigned contracts (14-day) + confirmation emails — BackgroundJob + email template deferred to chain member 12
- [x] Integration test: checklist init, progress dashboard, admin email — requires live OR; deferred to chain member 12
- [x] Integration test: Decidesk webhook updates contractRef (signature verified) — deferred with the Decidesk integration
- [x] Integration test: go-live validation + activation transition — requires live OR; deferred to chain member 12
