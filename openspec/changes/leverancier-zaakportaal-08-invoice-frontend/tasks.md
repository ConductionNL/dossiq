# Tasks — Member 08: Invoice Frontend (code)

> **Build status (hydra audit).** Greenfield. No supplier/leverancier schemas, services, or UI exist on dev (the in-tree zaakportaal is the citizen-side mijngemeente portal — separate concern, lives in lib/Service/Zaakportaal + src/views/portaal + lib/Settings/register.d/50-zaakportaal.json). The 16-member chain implements the supplier portal from scratch (Supplier* schemas, eHerkenning auth, RBAC, tender/invoice/contract/messaging surfaces, KPI dashboard, e2e tests). Tasks remain [ ] as genuine forward work.

Traces to giant task 2.3; spec REQ-004.

- [ ] Implement `InvoiceList` component: sortable/filterable table with status badges
- [ ] Fetch GET /api/supplier-portal/invoices with status/date/amount filters
- [ ] Create status badges: received, under_review, approved, disputed, rejected, paid
- [ ] Build `InvoiceDetail` page: expected payment date in green box if approved; actual + delta if paid
- [ ] Implement `AgeAnalysisBar`: stacked bar with 0–30/30–60/60–90/90+ buckets
- [ ] Bucket filtering: clicking a bucket filters the list to that age range
- [ ] Dispute entry: if status=disputed, show "Reactie geven" opening the message composer
- [ ] Red badge on 90+ day overdue invoices in the list
- [ ] Use NL Design System components; meet WCAG 2.1 AA
- [ ] Test age buckets with boundary edge cases
- [ ] Test forecast display states (approved/paid/under_review/disputed)
