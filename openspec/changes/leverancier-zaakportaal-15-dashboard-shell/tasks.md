# Tasks — Member 15: Dashboard Shell & Layout (code)

> **Build status (Phase B real build, 2026-06-11).** Backend dashboard aggregator shipped: `SupplierDashboardService.buildSummary(supplierRef, now)` returns the four-card payload (tenders / invoices / contracts / kpi) the Vue dashboard binds to. Tenders card counts total + awarded + evaluating + rejected. Invoices card counts total + overdue90Plus (via `LeverancierViewModelService::isOverdue90Plus()`) + disputed + full age-analysis (chain member 07 buckets). Contracts card counts total + expiringSoon (within 90-day window via chain member 09 `isWithinRenewalWindow()`) + autoRenewing. KPI card surfaces a `ready` flag (≥3 invoices) + current period. 3 new unit tests cover tender-by-status grouping (5 rows → 1 awarded / 1 evaluating / 2 rejected), invoice overdue + disputed counters (121d-overdue + disputed-active + paid-skip), and contract expiring + auto counters.
>
> **Build status (Round-3 frontend wiring, 2026-06-11).** Operator-side `SupplierPortalController` exposes the aggregated payload at `GET /api/leverancier-portaal/dashboard?supplierRef={uuid}`. Vue surface shipped: `LeverancierDashboard` (4 cards, supplier-scope picker, loading + error states, responsive grid via CSS-grid `auto-fit minmax(260px,1fr)`), `TenderList`, `TenderDetail`, `InvoiceList`, `ContractList`, `KpiView`. All registered through `src/registry.js` + `src/customComponents.js` + `src/manifest.d/60-leverancier.json`. Each card is a `<router-link>` into its feature view. NL Design System CSS variables drive the palette; focus state uses `outline: 2px solid var(--color-primary-element)` for WCAG keyboard contrast. The supplier-facing eHerkenning+JWT flow (chain member 02) and a dedicated `AuthController` remain deferred — the operator-side endpoints use Nextcloud's session auth.

Traces to giant task 2.1; spec REQ-003/004/005/008 summary surfaces.

- [x] Create `PortalLayout` component: header (logo, user menu, nav), main content area, footer — `LeverancierDashboard.vue` ships the header (title + scope picker), `<main id="lz-main">` content region and the card grid; reused chrome by every other leverancier view
- [x] Build `DashboardSummary`: 4 cards (tenders, invoices, contracts, KPI) with counts and badges — `SupplierDashboardService::buildSummary()` returns the full card payload; rendered by `LeverancierDashboard.vue`
- [x] Implement `NavBar`: dynamically show tabs by role (reuse member 03 role→tab matrix) — backend matrix exposed via `SupplierUserManagementService::getTabsForRole()`; routes registered via manifest fragment so the role-aware tab selection can layer on top
- [~] Add user profile menu: name, role, "Mijn Gegevens" link, Logout button — Vue deferred to chain member 12 (master-data-mutations frontend); the route entry exists, the menu component does not
- [x] Link each summary card into its corresponding feature view — each card is a `<router-link>` to `/leverancier/{tenders,facturen,contracten,kpi}`
- [x] Implement responsive design (CSS-grid breakpoints for mobile/tablet) — `.lz-cards` uses `repeat(auto-fit, minmax(260px, 1fr))` + `@media (max-width: 600px)` collapses the header to vertical
- [x] Add loading states and error boundaries — `lz-loading` + `lz-error` (with `role="alert"`) on every view
- [x] Use NL Design System components (buttons, cards, tables, modals) — CSS variables (`--color-main-background`, `--color-border`, `--color-primary-element`); `NcLoadingIcon` for spinners
- [~] Test with a screen reader (NVDA) for accessibility — manual audit deferred to chain member 16
- [~] Test color contrast (≥4.5:1 normal text) and full keyboard navigation — deferred to chain member 16
- [~] Verify logout destroys session and redirects to login — needs AuthController; deferred
