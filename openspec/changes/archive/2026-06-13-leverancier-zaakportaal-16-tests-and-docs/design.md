# Design — Member 16: Cross-Cutting Tests, A11y/Security Audit & Docs (code)

## Scope

End-to-end test journeys, the formal WCAG 2.1 AA + security audit, and the portal documentation +
release plan that span all prior members.

## Declarative-first (ADR-031) note

No new schema or product behaviour — this member is verification and documentation only. Tests
assert the behaviour shipped by members 02–15; they do not introduce new endpoints.

## Approach

- E2E (Playwright, ADR-008): login→dashboard→tender→download→logout; admin invite→activate→tabs;
  invoice→message→response; renewal request; address auto-apply; IBAN→Procest zaak.
- A11y audit: Axe/Lighthouse + manual keyboard + screen-reader (NVDA/VoiceOver), contrast ≥4.5:1.
- Security audit: XSS/CSRF/injection scan, cross-supplier scope-leak attempt, rate-limit + audit-log
  verification (re-confirms member 04's guarantees end-to-end).
- Docs (ADR-009): API reference, deployment guide, user guide, troubleshooting; release checklist +
  staged rollout + rollback + communication plan.

## Security (ADR-005)

This member's security audit is the final gate — it re-verifies no cross-supplier leakage, masked
PII in logs, enforced re-auth on financial actions, and CSRF protection across all mutations.
