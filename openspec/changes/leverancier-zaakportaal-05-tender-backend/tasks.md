# Tasks — Member 05: Tender Visibility Backend (code)

> **Build status (hydra audit).** Greenfield. No supplier/leverancier schemas, services, or UI exist on dev (the in-tree zaakportaal is the citizen-side mijngemeente portal — separate concern, lives in lib/Service/Zaakportaal + src/views/portaal + lib/Settings/register.d/50-zaakportaal.json). The 16-member chain implements the supplier portal from scratch (Supplier* schemas, eHerkenning auth, RBAC, tender/invoice/contract/messaging surfaces, KPI dashboard, e2e tests). Tasks remain [ ] as genuine forward work.

Traces to giant task 3.2; spec REQ-003.

- [ ] Implement `TenderVisibilityService.getTenderStatus(tenderId)` — scoped fetch + derived fields
- [ ] Implement `TenderVisibilityService.getEvaluationReport(tenderId)` — serve anonymized PDF
- [ ] Implement `TenderVisibilityService.getAwardLetter(tenderId)` — serve award-letter PDF
- [ ] Implement `TenderVisibilityService.getAppealDeadline(tenderId)` — rejection date + 20 days
- [ ] Create `TenderController`: GET /tenders, GET /tenders/{id}, GET /tenders/{id}/evaluation-report
- [ ] Apply member 04 scope validation to all tender endpoints
- [ ] Gate evaluation-report serving on the anonymized flag; log download events
- [ ] Return 403/404 for out-of-scope tender IDs
- [ ] Test all tender states (submitted, evaluating, awarded, rejected, withdrawn)
- [ ] Test PDF downloads and content headers
- [ ] Test appeal-deadline calculation
