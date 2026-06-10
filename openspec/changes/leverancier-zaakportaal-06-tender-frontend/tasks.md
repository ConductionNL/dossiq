# Tasks — Member 06: Tender Frontend (code)

> **Build status (hydra audit).** Greenfield. No supplier/leverancier schemas, services, or UI exist on dev (the in-tree zaakportaal is the citizen-side mijngemeente portal — separate concern, lives in lib/Service/Zaakportaal + src/views/portaal + lib/Settings/register.d/50-zaakportaal.json). The 16-member chain implements the supplier portal from scratch (Supplier* schemas, eHerkenning auth, RBAC, tender/invoice/contract/messaging surfaces, KPI dashboard, e2e tests). Tasks remain [ ] as genuine forward work.

Traces to giant task 2.2; spec REQ-003.

- [ ] Implement `TenderList` component: table with header-click sorting and status/date/search filtering
- [ ] Fetch GET /api/supplier-portal/tenders and bind to the table
- [ ] Create `TenderStatusBadge`: submitted=gray, evaluating=blue, awarded=green, rejected=red, withdrawn=orange
- [ ] Build `TenderDetail` page: all tender fields, conditional award/rejection sections
- [ ] Conditional rendering: award date + letter download if awarded; rejection reason + appeal deadline + report download if rejected
- [ ] Add document download buttons handling Content-Disposition: attachment
- [ ] Cache tender list ~5 minutes
- [ ] Use NL Design System components; meet WCAG 2.1 AA (keyboard nav, contrast, ARIA)
- [ ] Test sorting/filtering with 10+ tenders
- [ ] Test PDF download from the evaluation report
- [ ] Verify appeal-deadline formatting and accuracy
