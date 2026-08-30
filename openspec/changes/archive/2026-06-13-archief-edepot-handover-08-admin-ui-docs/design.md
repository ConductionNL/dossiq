# Design: archief-edepot-handover-08-admin-ui-docs

## Scope

DIV-facing UI (retention-rule CRUD + dashboard), cross-service test suite, and documentation over the backend from members 01–07. No new backend capability beyond rule CRUD + dashboard stats.

## Declarative-first (ADR-031)

The rule data is declarative (`BewaarTermijnRegel`, member 01); this member adds only the thin CRUD endpoints and the Vue forms/dashboard that read/write it. ADR-004 frontend conventions (Vue, NcSelect labels, modal isolation, initial-state) apply.

## Data access (ADR-001)

Rule CRUD and dashboard stats read/write via the OpenRegister ObjectService. No bespoke SQL.

## Service layout

### ArchivalRuleController
- `GET/POST/PUT/DELETE /api/archief/rules` over `BewaarTermijnRegel`; validation `bewaartermijnJaren ≥ 1` or "permanent", `zaaktypeKey` must be a known zaaktype.

### ArchivalDashboardController + view
- `GET /api/archief/dashboard/stats` → {ready, inProgress, failed, completed, totalTransferred}. Dashboard view: stat cards, triggers table, batch-jobs table, quick actions (initiate batch, retry failed, view proof). Vue + NL Design CSS variables; NcSelect inputs carry labels; modals isolated.

### Tests
- Unit tests for the services across members 02–07 (trigger daemon, bundler, exporter, BagIt bundler, submitter, retry daemon, proof recorder, rollback manager, batch processor).
- Integration: end-to-end happy path (trigger → bundle → submit → proof) and failure path (bundling/submission fails → DIV notified → corrected → retry succeeds); batch of 50.

### Documentation
- Admin guide, developer guide, e-Depot integration guide.

## Security (ADR-005)

Rule CRUD and dashboard endpoints are admin/DIV operations and MUST declare an explicit admin auth posture; mutations validate input before writing. No public surface.

## i18n (ADR-007)

All UI strings use `t('procest', ...)`; Dutch + English provided (the fleet i18n minimum).

## Traceability

Giant Task 19 (rule UI), Task 20 (dashboard), Task 21 (tests), Task 22 (documentation).
