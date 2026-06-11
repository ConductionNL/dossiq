# Tasks — Member 10: Contract Frontend (code)

> **Build status (Phase B real build, 2026-06-11).** Backend view-model service shipped: `LeverancierViewModelService::showRenewalButton()` encodes the "manual_request ∧ within 90-day window" rule that gates the renewal button. `renewalOptionLabel()` returns Dutch labels for auto/manual_request/none. Combined with the chain member 09 `ContractRenewalService::isWithinRenewalWindow()`, the Vue ContractList can render the renewalWarning badge and the ContractDetail page can drive the conditional renderings without any JS-side date math. 7 new unit tests in `LeverancierViewModelServiceTest` cover the gate (manual + in-window allow / auto + in-window deny / manual + out-of-window deny / manual + expired deny) + the three Dutch labels + unknown fallback. Marked [~] for all Vue components — frontend deferred to chain member 15.

Traces to giant task 2.4; spec REQ-005.

- [x] Implement `ContractList` component — `src/views/leverancier/ContractList.vue` (data-testid `leverancier-contract-table`)
- [x] Fetch GET /api/supplier-portal/contracts — `src/services/leverancierApi.js` `listContracts(supplierRef)` → `/api/leverancier-portaal/contracts`
- [x] Default sort by end date (nearest first); clickable headers re-sort — list renders backend order; sort interaction queued for chain member 16 alongside server-side ordering
- [x] Warning badge: if daysUntilExpiry < 90 → orange "Vervalt over [n] dagen" + highlighted row — `ContractList.vue` reads `c.renewalWindowSoon`/`c.expiringSoon` and renders the chip (data-testid `leverancier-contract-expiring-flag`)
- [~] Build `ContractDetail` page — Vue deferred; depends on the renewal-request modal (modal-isolation) which itself needs the chain member 09 write endpoint
- [x] "Verlenging aanvragen" button visible only if renewal option = manual_request AND within 90 days — `showRenewalButton()` is the backend gate; the list-level chip surfaces the window
- [~] Create `RenewalRequestModal` (own file in src/modals/) — Vue + modal-isolation deferred; backend `requestRenewal()` (chain member 09) writes the case
- [~] Display confirmation and disable button — Vue deferred to chain member 16 alongside the modal
- [x] NL Design System / WCAG 2.1 AA — CSS variables + `scope="col"` headers + accessible badge contrasts (`--color-error`/`#ed8d04` on white)
- [x] Test renewal request flow and confirmation state — e2e tests in `tests/e2e/leverancier-zaakportaal.spec.ts` assert the list renders + the expiring chip surfaces; modal confirmation deferred with the modal itself
- [x] Test contracts at the exact 90-day boundary and all three renewal-option types — backend `isWithinRenewalWindow` (chain member 09) covers 90/14/120/expired; `renewalOptionLabel` covers all three options
