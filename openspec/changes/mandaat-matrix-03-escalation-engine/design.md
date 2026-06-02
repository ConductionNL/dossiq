# Design — Member 03: Escalation Engine (code)

## Scope

`MandaatEscalatieService`, `EscalatieApprovalService`, `MandaatEscalatieController`. Reads/writes
the `MandaatEscalatie` schema (member 01) via ObjectService (ADR-001); consumes the
`MandaatCheckService` verdict (member 02) for the re-check on approval.

## Service contract

`MandaatEscalatieService.createEscalatie(zaakId, decisionType, initiatorId, reden)`:
- validate inputs;
- `resolveEscalatiePath(decisionType, reden)` → find the next-higher mandaat (Mandaat for this
  decisionType ordered by plafond DESC that exceeds the initiator's authority) and its
  gemandateerdeRol → current MedewerkerRolToewijzing holder;
- create MandaatEscalatie `status: "open"`, `escalatiePadEindigtBij` = resolved holder;
- notify recipient; optionally set case status "Wacht op besluit hoger mandaat";
- return escalatieId.

`autoRerouteOnPersonnelChange(oldUserId, newUserId, rolId)` — update open escalaties where
`escalatiePadEindigtBij = oldUserId` AND role = rolId → newUserId; notify old + new recipients.

`EscalatieApprovalService.approveEscalatie(escalatieId, mandaathouderUserId)` — validate open +
authorized; execute the underlying decision via the case/workflow API; create a MandaatGebruik log
(via member 05's MandaatGebruikService); set status "goedgekeurd"; notify initiator.
`rejectEscalatie(escalatieId, reason)` — set status "afgewezen", store reason, do NOT execute, revert
case status, notify initiator.

## API design (ADR-002 / ADR-016)

Routes registered only via `appinfo/routes.php`:
- `POST /api/mandate/escalatie/{escalatieId}/approve`
- `POST /api/mandate/escalatie/{escalatieId}/reject` (payload `{reason}`)
- `GET /api/mandate/escalatie` (open escalaties for the logged-in user)
- `GET /api/mandate/escalatie/{escalatieId}` (detail)

## Security (ADR-005, ADR-023)

Approve/reject are authenticated, non-admin endpoints guarded per-object: the caller MUST be the
`escalatiePadEindigtBij` holder (or hold the required mandate) — verified server-side by re-running
`MandaatCheckService.isAuthorized()` for the mandaathouder before executing the decision. This
prevents IDOR (any authed user approving an arbitrary escalation). List/detail are scoped to the
logged-in user's escalaties.
