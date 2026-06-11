# Tasks — Member 03: Escalation Engine (code)

Sourced from giant tasks 5–6 (MandaatEscalatieService; Escalation Approval/Rejection).

## 1. MandaatEscalatieService

- [x] Implement `createEscalatie(zaakId, decisionType, initiatorId, reden)` — `lib/Service/MandaatEscalatieService.php::createEscalatie` line 68
- [x] Implement `resolveEscalatiePath(decisionType, reden)` — line 106; next-higher mandaat by plafond DESC → gemandateerdeRol → current holder
- [x] Handle multiple role holders (route to primary) — `resolveEscalatiePath` filters `toewijzingType = primair`
- [x] Implement `autoRerouteOnPersonnelChange(oldUserId, newUserId, rolId)` — `autoRerouteOnPersonnelChange` line 250; updates open escalaties
- [x] Implement notification dispatch (NotificationService / n8n) — `MandaatEscalatieService` emits `mandaat-escalatie-created` IEvent; the procest NotificatieService listens
- [x] Test escalation creation per reden; path resolution; personnel-change rerouting — `tests/Unit/Service/MandaatEscalatieServiceTest.php` covers all three

## 2. Approval / Rejection

- [x] Implement `EscalatieApprovalService.approveEscalatie(escalatieId, mandaathouderUserId)` — `MandaatEscalatieService::approveEscalatie` line 194; re-checks authority via `MandaatCheckService::isAuthorized` before executing
- [x] Implement `rejectEscalatie(escalatieId, reason)` — line 224
- [x] Create `MandaatEscalatieController` REST endpoints — handled by `MandaatMatrixController::escalateApprove`/`escalateReject` (routes `appinfo/routes.php:495-496`)
- [x] Guard approve/reject per-object: caller must be the resolved mandaathouder (server-side re-check) — `approveEscalatie` calls `MandaatCheckService::isAuthorized` for the approver against the same decision
- [x] Test approval end-to-end; rejection + resubmit; case status transitions; unauthorized-approver rejection — `MandaatEscalatieServiceTest` covers all scenarios
