# Design — Member 15: Dashboard Shell & Layout (code)

## Scope

The portal shell: layout, role-aware nav, dashboard summary cards, profile menu, responsive +
accessibility. Frontend-only; reads the existing feature APIs for summary counts.

## Declarative-first (ADR-031) note

No new schema or backend behaviour. Per ADR-024 the portal's routes and navigation are declared in
the app manifest; this member adds the layout shell those routes mount into. Summary card counts
come from the already-shipped scoped feature endpoints (05/07/09/13).

## Approach

- `PortalLayout` — header (logo, user menu, nav), main content, footer.
- `NavBar` — dynamically shows tabs by role (reuses the member 03 role→tab matrix).
- `DashboardSummary` — 4 cards: open tenders, unpaid invoices, expiring contracts, KPI headline,
  each with a count/status badge and a link into the corresponding feature view.
- Profile menu (name, role, "Mijn Gegevens", logout); responsive CSS-grid breakpoints; loading +
  error boundaries.

## Security (ADR-005)

Nav visibility is presentational only — every linked view is independently scope-guarded by its own
backend (member 04). Logout destroys the session. NL Design System + WCAG 2.1 AA (keyboard nav,
contrast ≥4.5:1, screen-reader tested) per ADR-010.
