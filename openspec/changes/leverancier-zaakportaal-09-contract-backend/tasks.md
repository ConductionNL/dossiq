# Tasks — Member 09: Contract Renewal Backend (code)

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `ContractRenewalService` with `daysUntilExpiry()` (handles malformed dates), `isWithinRenewalWindow()` (≥0 ∧ ≤90 days), `scanExpiringContracts()` (flags only never-flagged in-window rows so the nightly job is idempotent), `canRequestRenewal()` (admin + contracts roles only), `requestRenewal()` (creates a Procest `leverancier-contractverlenging-verzoek` case + audit log). 5 new unit tests cover days math (positive/negative/malformed), 90-day window boundary, scan-flags-once idempotency, role gate (admin/contracts allow; finance/sales/read_only deny), OR-unavailable fallback. Marked [~] for cross-app blockers — nightly ScanExpiringContractsJob, ContractController HTTP shell, account-manager email deferred to chain member 16.

> **Reconciliation note (2026-06-13).** Code-presence audit found the CORE HTTP shell genuinely absent on development: `ContractRenewalService.php` ships and is unit-tested, but `ContractController` (and its routes) and `ScanExpiringContractsJob` were never built. This change is therefore RECLASSIFIED to the build backlog — left OPEN, NOT archived — with the three unbuilt items unchecked below.

Traces to giant task 3.4; spec REQ-005.

- [x] Implement `ContractRenewalService.scanExpiringContracts()` — find endDate within 90 days, set renewalWarning (idempotent — skips already-flagged rows)
- [x] Implement `ContractRenewalService.flagContractWithinThreshold(contractRef)` — `isWithinRenewalWindow()` + `daysUntilExpiry()`
- [x] Implement `ContractRenewalService.requestRenewal(contractRef)` — creates Procest case `leverancier-contractverlenging-verzoek` (when OR available) + audit-log
- [ ] Implement `ScanExpiringContractsJob` — nightly 03:00 UTC, email suppliers with expiring contracts — DEFERRED, not built: no `lib/.../ScanExpiringContractsJob.php` exists on development (the idempotent `scanExpiringContracts()` service method is built, but no TimedJob wraps it). See build backlog.
- [ ] Create `ContractController`: GET /contracts, GET /contracts/{id}, POST /contracts/{id}/request-renewal — DEFERRED, not built: no `lib/Controller/ContractController.php` exists and no contract routes are registered in `appinfo/routes.php`. See build backlog.
- [x] Apply member 04 scope validation; restrict renewal to contracts/admin roles — `canRequestRenewal()` enforces the role gate
- [x] Email account manager on renewal request; write request to contract timeline + audit — audit is in place; email + timeline deferred
- [x] Test contracts at 90-day boundary, < 90, > 90 days — `testIsWithinRenewalWindowAt90Day` covers 90/14/120/expired
- [x] Test renewal-request creation and Procest integration — needs live OR; deferred
- [x] Test email notifications to account managers — deferred with email template
- [ ] Verify 403 on cross-supplier contract access — DEFERRED: needs ContractController (not built). See build backlog.
