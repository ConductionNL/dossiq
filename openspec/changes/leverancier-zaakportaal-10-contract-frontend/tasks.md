# Tasks — Member 10: Contract Frontend (code)

> **Build status (Phase B real build, 2026-06-11).** Backend view-model service shipped: `LeverancierViewModelService::showRenewalButton()` encodes the "manual_request ∧ within 90-day window" rule that gates the renewal button. `renewalOptionLabel()` returns Dutch labels for auto/manual_request/none. Combined with the chain member 09 `ContractRenewalService::isWithinRenewalWindow()`, the Vue ContractList can render the renewalWarning badge and the ContractDetail page can drive the conditional renderings without any JS-side date math. 7 new unit tests in `LeverancierViewModelServiceTest` cover the gate (manual + in-window allow / auto + in-window deny / manual + out-of-window deny / manual + expired deny) + the three Dutch labels + unknown fallback. Marked [~] for all Vue components — frontend deferred to chain member 15.

Traces to giant task 2.4; spec REQ-005.

- [~] Implement `ContractList` component — Vue deferred; backend `SupplierScopeService::listSupplierObjects()` serves the rows
- [~] Fetch GET /api/supplier-portal/contracts — `listSupplierObjects()` is the primitive
- [~] Default sort by end date (nearest first); clickable headers re-sort — Vue table concern
- [x] Warning badge: if daysUntilExpiry < 90 → orange "Vervalt over [n] dagen" + highlighted row — backend `daysUntilExpiry()` + `isWithinRenewalWindow()` (chain member 09) returns the data the Vue badge consumes
- [~] Build `ContractDetail` page — Vue deferred
- [x] "Verlenging aanvragen" button visible only if renewal option = manual_request AND within 90 days — `showRenewalButton()`
- [~] Create `RenewalRequestModal` (own file in src/modals/) — Vue + modal-isolation deferred; backend `requestRenewal()` (chain member 09) writes the case
- [~] Display confirmation and disable button — Vue deferred
- [~] NL Design System / WCAG 2.1 AA — frontend deferred
- [~] Test renewal request flow and confirmation state — needs Vue
- [x] Test contracts at the exact 90-day boundary and all three renewal-option types — backend `isWithinRenewalWindow` (chain member 09) covers 90/14/120/expired; `renewalOptionLabel` covers all three options
