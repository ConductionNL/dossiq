# Tasks — Member 15: Dashboard Shell & Layout (code)

> **Build status (hydra audit).** Greenfield. No supplier/leverancier schemas, services, or UI exist on dev (the in-tree zaakportaal is the citizen-side mijngemeente portal — separate concern, lives in lib/Service/Zaakportaal + src/views/portaal + lib/Settings/register.d/50-zaakportaal.json). The 16-member chain implements the supplier portal from scratch (Supplier* schemas, eHerkenning auth, RBAC, tender/invoice/contract/messaging surfaces, KPI dashboard, e2e tests). Tasks remain [ ] as genuine forward work.

Traces to giant task 2.1; spec REQ-003/004/005/008 summary surfaces.

- [ ] Create `PortalLayout` component: header (logo, user menu, nav), main content area, footer
- [ ] Build `DashboardSummary`: 4 cards (tenders, invoices, contracts, KPI) with counts and badges
- [ ] Implement `NavBar`: dynamically show tabs by role (reuse member 03 role→tab matrix)
- [ ] Add user profile menu: name, role, "Mijn Gegevens" link, Logout button
- [ ] Link each summary card into its corresponding feature view
- [ ] Implement responsive design (CSS-grid breakpoints for mobile/tablet)
- [ ] Add loading states and error boundaries
- [ ] Use NL Design System components (buttons, cards, tables, modals)
- [ ] Test with a screen reader (NVDA) for accessibility
- [ ] Test color contrast (≥4.5:1 normal text) and full keyboard navigation
- [ ] Verify logout destroys session and redirects to login
