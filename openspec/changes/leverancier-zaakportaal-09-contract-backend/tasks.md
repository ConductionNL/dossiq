# Tasks — Member 09: Contract Renewal Backend (code)

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `ContractRenewalService` with `daysUntilExpiry()` (handles malformed dates), `isWithinRenewalWindow()` (≥0 ∧ ≤90 days), `scanExpiringContracts()` (flags only never-flagged in-window rows so the nightly job is idempotent), `canRequestRenewal()` (admin + contracts roles only), `requestRenewal()` (creates a Procest `leverancier-contractverlenging-verzoek` case + audit log). 5 new unit tests cover days math (positive/negative/malformed), 90-day window boundary, scan-flags-once idempotency, role gate (admin/contracts allow; finance/sales/read_only deny), OR-unavailable fallback. Marked [~] for cross-app blockers — nightly ScanExpiringContractsJob, ContractController HTTP shell, account-manager email deferred to chain member 16.

Traces to giant task 3.4; spec REQ-005.

- [x] Implement `ContractRenewalService.scanExpiringContracts()` — find endDate within 90 days, set renewalWarning (idempotent — skips already-flagged rows)
- [x] Implement `ContractRenewalService.flagContractWithinThreshold(contractRef)` — `isWithinRenewalWindow()` + `daysUntilExpiry()`
- [x] Implement `ContractRenewalService.requestRenewal(contractRef)` — creates Procest case `leverancier-contractverlenging-verzoek` (when OR available) + audit-log
- [~] Implement `ScanExpiringContractsJob` — nightly 03:00 UTC, email suppliers with expiring contracts — TimedJob wired around `scanExpiringContracts()`; email deferred to chain member 16
- [~] Create `ContractController`: GET /contracts, GET /contracts/{id}, POST /contracts/{id}/request-renewal — manifest renderer serves CRUD; renewal endpoint deferred
- [x] Apply member 04 scope validation; restrict renewal to contracts/admin roles — `canRequestRenewal()` enforces the role gate
- [~] Email account manager on renewal request; write request to contract timeline + audit — audit is in place; email + timeline deferred
- [x] Test contracts at 90-day boundary, < 90, > 90 days — `testIsWithinRenewalWindowAt90Day` covers 90/14/120/expired
- [~] Test renewal-request creation and Procest integration — needs live OR; deferred
- [~] Test email notifications to account managers — deferred with email template
- [~] Verify 403 on cross-supplier contract access — needs ContractController; deferred
