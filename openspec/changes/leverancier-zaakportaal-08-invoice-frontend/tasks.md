# Tasks — Member 08: Invoice Frontend (code)

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
