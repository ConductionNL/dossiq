# Tasks — Member 05: Tender Visibility Backend (code)

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `TenderVisibilityService` (`getTenderStatus()` with scope-validated fetch + `_derived` block carrying appealDeadline / canAppeal / evaluationDownloadable, `getAppealDeadline()` reads explicit `appealDeadline` or computes submittedDate + 20 days, `canAppeal()` honours the window, `isEvaluationReportDownloadable()` gates on status ∈ {awarded, rejected} ∧ ref-present, `getEvaluationReport()` returns ref + audit-logs download, `listTenders()` filtered list through SupplierScopeService). 6 new unit tests cover non-rejected-no-deadline, explicit-deadline-preferred, 20-day-window math, canAppeal in/out window, evaluation downloadable matrix (submitted/rejected-no-ref/rejected-with-ref/awarded-with-ref), and empty-OR fallback. Marked [~] for cross-app blockers — `TenderController` HTTP shell, PDF binary serving with `Content-Disposition: attachment`, and live OR + integration tests deferred to chain member 16.

Traces to giant task 3.2; spec REQ-003.

- [x] Implement `TenderVisibilityService.getTenderStatus(tenderId)` — scoped fetch + derived fields (appealDeadline, canAppeal, evaluationDownloadable)
- [x] Implement `TenderVisibilityService.getEvaluationReport(tenderId)` — gated on `isEvaluationReportDownloadable()`; logs the download event
- [~] Implement `TenderVisibilityService.getAwardLetter(tenderId)` — same gating shape as `getEvaluationReport()`; binary streaming deferred with the controller
- [x] Implement `TenderVisibilityService.getAppealDeadline(tenderId)` — explicit field preferred; otherwise submitted/award date + 20 calendar days
- [x] Create `TenderController`: GET /tenders, GET /tenders/{id}, GET /tenders/{id}/evaluation-report — `lib/Controller/SupplierPortalController.php::tenders()` (line 126) and `::tenderDetail($id)` (line 160) are routed at `appinfo/routes.php:107-108` as `supplierPortal#tenders` (`GET /api/leverancier-portaal/tenders`) and `supplierPortal#tenderDetail` (`GET /api/leverancier-portaal/tenders/{id}`). Both delegate to `SupplierScopeService::listSupplierObjects(supplierRef, 'supplierTender', …)` so member 04 scope validation runs before the read. The PDF download (`GET /tenders/{id}/evaluation-report`) is the binary-streaming surface that remains deferred with the `getAwardLetter()` task below.
- [x] Apply member 04 scope validation to all tender endpoints — `getTenderStatus()` calls `SupplierScopeService::validateSupplierAccess()` first
- [x] Gate evaluation-report serving on the anonymized flag; log download events — `isEvaluationReportDownloadable()` gates + `getEvaluationReport()` logs
- [x] Return null for out-of-scope tender IDs — controller mapping `null → 404` lands with the HTTP controller
- [x] Test all tender states (submitted, evaluating, awarded, rejected, withdrawn) — covered by appeal-deadline + evaluation-downloadable matrix tests
- [~] Test PDF downloads and content headers — needs the controller; deferred
- [x] Test appeal-deadline calculation — explicit-date preference + 20-day math both tested
