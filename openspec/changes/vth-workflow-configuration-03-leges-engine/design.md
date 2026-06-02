# Design: vth-workflow-configuration-03-leges-engine

## Architecture

`kind: code` member (ADR-032). `LegesCalculationService` consumes the declarative leges rule sets seeded by member 01 (ADR-031: rules are data; the engine evaluates them). Controller → Service → ObjectService layering (ADR-003, ADR-001).

## Service Layout

- `calculateFee(caseId)` → `{baseFee, modifiers, totalFee, rules}`, evaluating the active rule set for the case's zaaktype/activiteit.
- `applyVerrekening(caseId, priorFee)` → `{offset, finalFee}`.
- `refund(caseId, reason)` → `{refundAmount, status}`.
- `navordering(caseId, amount, reason)` records an additional fee and notifies.
- All transactions append to an audit trail stored via ObjectService (ADR-022 audit-on-data).

## Security (ADR-005)

`LegesController` endpoints are authenticated. Mutating endpoints validate the caseId belongs to the caller's accessible cases (no IDOR — per-object guard before mutation). Inputs (amounts, reasons) are validated server-side.
