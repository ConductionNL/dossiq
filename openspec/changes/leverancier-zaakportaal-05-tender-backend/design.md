# Design — Member 05: Tender Visibility Backend (code)

## Scope

Backend service + scoped API for tender status, award/rejection detail, appeal deadlines, and
anonymized document download. Reads the `SupplierTender` schema from member 01.

## Declarative-first (ADR-031) note

No new schema. `SupplierTender` records are read via the OpenRegister ObjectService (ADR-001).
Derived fields (daysUntilAppealDeadline) are computed in the service, not stored.

## Approach

- `getTenderStatus(tenderId)` fetches the scoped `SupplierTender`, derives appeal-deadline
  countdown.
- `getAppealDeadline(tenderId)` = rejection date + 20 days.
- `getEvaluationReport(tenderId)` / `getAwardLetter(tenderId)` validate the PDF is marked
  anonymized and serve with `Content-Disposition: attachment`, logging the download.
- `TenderController` exposes list/detail/report; every endpoint runs through the member 04 scope
  validation before returning data.

## Security (ADR-005)

- All endpoints scope-validated (no cross-supplier tender access) via member 04.
- Evaluation report served only when flagged anonymized — prevents leaking other bidders' data
  (Aanbestedingswet 2.130).
- Download events audit-logged.
- 404 vs 403 distinction avoids confirming existence of out-of-scope tenders.
