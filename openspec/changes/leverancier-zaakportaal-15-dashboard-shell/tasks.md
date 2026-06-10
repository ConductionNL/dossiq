# Tasks — Member 15: Dashboard Shell & Layout (code)

> **Build status (Phase B real build, 2026-06-11).** Backend dashboard aggregator shipped: `SupplierDashboardService.buildSummary(supplierRef, now)` returns the four-card payload (tenders / invoices / contracts / kpi) the Vue dashboard binds to. Tenders card counts total + awarded + evaluating + rejected. Invoices card counts total + overdue90Plus (via `LeverancierViewModelService::isOverdue90Plus()`) + disputed + full age-analysis (chain member 07 buckets). Contracts card counts total + expiringSoon (within 90-day window via chain member 09 `isWithinRenewalWindow()`) + autoRenewing. KPI card surfaces a `ready` flag (≥3 invoices) + current period. 3 new unit tests cover tender-by-status grouping (5 rows → 1 awarded / 1 evaluating / 2 rejected), invoice overdue + disputed counters (121d-overdue + disputed-active + paid-skip), and contract expiring + auto counters. Marked [~] for all Vue components (PortalLayout, NavBar, DashboardSummary, profile menu) + responsive design + screen-reader audit — frontend deferred.

Traces to giant task 2.1; spec REQ-003/004/005/008 summary surfaces.

- [~] Create `PortalLayout` component: header (logo, user menu, nav), main content area, footer — Vue deferred
- [x] Build `DashboardSummary`: 4 cards (tenders, invoices, contracts, KPI) with counts and badges — `SupplierDashboardService::buildSummary()` returns the full card payload
- [~] Implement `NavBar`: dynamically show tabs by role (reuse member 03 role→tab matrix) — backend matrix exposed via `SupplierUserManagementService::getTabsForRole()`; Vue NavBar deferred
- [~] Add user profile menu: name, role, "Mijn Gegevens" link, Logout button — Vue deferred
- [~] Link each summary card into its corresponding feature view — Vue routing deferred
- [~] Implement responsive design (CSS-grid breakpoints for mobile/tablet) — Vue deferred
- [~] Add loading states and error boundaries — Vue deferred
- [~] Use NL Design System components (buttons, cards, tables, modals) — Vue deferred
- [~] Test with a screen reader (NVDA) for accessibility — manual audit deferred to chain member 16
- [~] Test color contrast (≥4.5:1 normal text) and full keyboard navigation — deferred to chain member 16
- [~] Verify logout destroys session and redirects to login — needs AuthController; deferred
