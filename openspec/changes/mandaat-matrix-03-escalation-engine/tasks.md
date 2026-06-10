# Tasks — Member 03: Escalation Engine (code)

Sourced from giant tasks 5–6 (MandaatEscalatieService; Escalation Approval/Rejection).

## 1. MandaatEscalatieService

- [ ] Implement `createEscalatie(zaakId, decisionType, initiatorId, reden)` — validate, resolve path, create record, notify, set case status
- [ ] Implement `resolveEscalatiePath(decisionType, reden)` — next-higher mandaat by plafond DESC → gemandateerdeRol → current holder
- [ ] Handle multiple role holders (route to primary)
- [ ] Implement `autoRerouteOnPersonnelChange(oldUserId, newUserId, rolId)` — update open escalaties + notify
- [ ] Implement notification dispatch (NotificationService / n8n)
- [ ] Test escalation creation per reden; path resolution; personnel-change rerouting

## 2. Approval / Rejection

- [ ] Implement `EscalatieApprovalService.approveEscalatie(escalatieId, mandaathouderUserId)` — re-check authority, execute decision, log MandaatGebruik, status goedgekeurd, notify
- [ ] Implement `rejectEscalatie(escalatieId, reason)` — status afgewezen, store reason, no execution, revert case, notify
- [ ] Create `MandaatEscalatieController` REST endpoints: approve, reject, list (GET), detail (GET) — registered in appinfo/routes.php
- [ ] Guard approve/reject per-object: caller must be the resolved mandaathouder (server-side re-check)
- [ ] Test approval end-to-end; rejection + resubmit; case status transitions; unauthorized-approver rejection
