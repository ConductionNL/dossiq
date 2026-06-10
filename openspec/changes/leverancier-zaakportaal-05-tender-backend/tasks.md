# Tasks — Member 05: Tender Visibility Backend (code)

Traces to giant task 3.2; spec REQ-003.

- [~] Implement `TenderVisibilityService.getTenderStatus(tenderId)` — scoped fetch + derived fields — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `TenderVisibilityService.getEvaluationReport(tenderId)` — serve anonymized PDF — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `TenderVisibilityService.getAwardLetter(tenderId)` — serve award-letter PDF — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `TenderVisibilityService.getAppealDeadline(tenderId)` — rejection date + 20 days — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `TenderController`: GET /tenders, GET /tenders/{id}, GET /tenders/{id}/evaluation-report — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Apply member 04 scope validation to all tender endpoints — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Gate evaluation-report serving on the anonymized flag; log download events — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Return 403/404 for out-of-scope tender IDs — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test all tender states (submitted, evaluating, awarded, rejected, withdrawn) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test PDF downloads and content headers — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test appeal-deadline calculation — deferred to downstream cycle / fleet-wide adoption (handoff)
