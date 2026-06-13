---
kind: code
depends_on: [mandaat-matrix-04-decidesk-import]
chain:
  - mandaat-matrix-01-schema-foundation
  - mandaat-matrix-02-authorization-engine
  - mandaat-matrix-03-escalation-engine
  - mandaat-matrix-04-decidesk-import
  - mandaat-matrix-05-case-decision-integration
  - mandaat-matrix-06-temporal-and-conflict
  - mandaat-matrix-07-admin-ui
  - mandaat-matrix-08-user-ui
  - mandaat-matrix-09-tests-and-docs
---

# Proposal: Mandaat-matrix — Member 05: Case Decision Integration + Audit Logging (code)

Member **5 of 9** in the `mandaat-matrix` chain. Predecessor:
`mandaat-matrix-04-decidesk-import`. This member wires the authorization check into the case
decision flow (`CaseDecisionActionListener`) and implements the immutable audit log
(`MandaatGebruikService`). Before any case decision executes, `MandaatCheckService.isAuthorized()`
(member 02) is invoked; authorized decisions log a MandaatGebruik snapshot; denied decisions
dispatch an escalation (member 03).

## Why

The authorization verdict and escalation engine are only valuable when enforced at the actual
decision point and when every authorized decision leaves an immutable, queryable audit trail
(Awb art. 3:4, NEN 7510, ISO 27001 A.9). This member closes the enforcement + audit loop.

## What Changes

1. **`CaseDecisionActionListener`** — intercepts decision actions, calls `isAuthorized()`, blocks +
   escalates on denial, proceeds + logs on success.
2. **`MandaatGebruikService`** — immutable per-decision log with role/mandate/conditions snapshot;
   API-layer write-once enforcement; audit-trail retrieval/export.

## Out of Scope (this member)

Temporal version selection (member 06) and belangenconflict (member 06) refine the check; UI
(07–08); compliance CSV export polish (the queryable trail lands here, formatted export detail in
member 09 docs).

## Dependencies

- **mandaat-matrix-02-authorization-engine** (REQUIRED) — isAuthorized
- **mandaat-matrix-03-escalation-engine** (REQUIRED) — escalation dispatch on denial
- **mandaat-matrix-01-schema-foundation** (REQUIRED) — MandaatGebruik schema
