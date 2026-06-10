# Tasks — Member 03: Escalation Engine (code)

Sourced from giant tasks 5–6 (MandaatEscalatieService; Escalation Approval/Rejection).

## 1. MandaatEscalatieService

- [~] Implement `createEscalatie(zaakId, decisionType, initiatorId, reden)` — validate, resolve path, create record, notify, set case status — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `resolveEscalatiePath(decisionType, reden)` — next-higher mandaat by plafond DESC → gemandateerdeRol → current holder — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Handle multiple role holders (route to primary) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `autoRerouteOnPersonnelChange(oldUserId, newUserId, rolId)` — update open escalaties + notify — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement notification dispatch (NotificationService / n8n) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test escalation creation per reden; path resolution; personnel-change rerouting — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Approval / Rejection

- [~] Implement `EscalatieApprovalService.approveEscalatie(escalatieId, mandaathouderUserId)` — re-check authority, execute decision, log MandaatGebruik, status goedgekeurd, notify — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `rejectEscalatie(escalatieId, reason)` — status afgewezen, store reason, no execution, revert case, notify — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `MandaatEscalatieController` REST endpoints: approve, reject, list (GET), detail (GET) — registered in appinfo/routes.php — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Guard approve/reject per-object: caller must be the resolved mandaathouder (server-side re-check) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test approval end-to-end; rejection + resubmit; case status transitions; unauthorized-approver rejection — deferred to downstream cycle / fleet-wide adoption (handoff)
