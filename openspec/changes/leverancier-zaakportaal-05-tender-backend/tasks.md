# Tasks — Member 05: Tender Visibility Backend (code)

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
