# Tasks — Member 10: Contract Frontend (code)

> **Build status (hydra audit).** Greenfield. No supplier/leverancier schemas, services, or UI exist on dev (the in-tree zaakportaal is the citizen-side mijngemeente portal — separate concern, lives in lib/Service/Zaakportaal + src/views/portaal + lib/Settings/register.d/50-zaakportaal.json). The 16-member chain implements the supplier portal from scratch (Supplier* schemas, eHerkenning auth, RBAC, tender/invoice/contract/messaging surfaces, KPI dashboard, e2e tests). Tasks remain [ ] as genuine forward work.

Traces to giant task 2.4; spec REQ-005.

- [ ] Implement `ContractList` component: columns number/subject/start/end/value/account manager/renewal option
- [ ] Fetch GET /api/supplier-portal/contracts and bind to the table
- [ ] Default sort by end date (nearest first); clickable headers re-sort
- [ ] Warning badge: if daysUntilExpiry < 90, orange "Vervalt over [n] dagen" + highlighted row
- [ ] Build `ContractDetail` page: all fields, conditional renewal-option details
- [ ] "Verlenging aanvragen" button visible only if renewal option = manual_request AND within 90 days
- [ ] Create `RenewalRequestModal` (own file in src/modals/): confirmation, POST request-renewal
- [ ] Display confirmation and disable button: "Verlenging aangevraagd op [date]"
- [ ] Use NL Design System components; meet WCAG 2.1 AA
- [ ] Test renewal request flow and confirmation state
- [ ] Test contracts at the exact 90-day boundary and all three renewal-option types
