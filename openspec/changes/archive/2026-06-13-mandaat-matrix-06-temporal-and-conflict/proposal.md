---
kind: code
depends_on: [mandaat-matrix-05-case-decision-integration]
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

# Proposal: Mandaat-matrix — Member 06: Temporal Queries + Conflict of Interest (code)

Member **6 of 9** in the `mandaat-matrix` chain. Predecessor:
`mandaat-matrix-05-case-decision-integration`. This member adds two refinements to the
authorization pipeline: effective-dating (`MandaatQueryService` — resolve the correct mandate
version for a decision date, future-scheduling suggestion) and belangenconflict detection
(`ConflictOfInterestService` — automatic BRP relationship check + manual registration), both
plugged into `MandaatCheckService.isAuthorized()`.

## Why

Mandaatregelingen change over time (retroactive/future-dated); authorization and audit must use the
version effective on the decision date, not the current one. Separately, decision-makers with a
personal interest (family relationship to the applicant) must be blocked (Awb integrity). Both are
authorization-pipeline refinements that depend on the enforcement point being in place (member 05).

## What Changes

1. **`MandaatQueryService`** — `getMandaatAsOf(mandaatId, date)`, optional `decisionDate` on
   `isAuthorized()`, version recorded in MandaatGebruik, `suggestFutureDate()`.
2. **`ConflictOfInterestService`** — `checkConflict(userId, zaakId)` (BRP relationship), manual
   conflict registration, escalation with reden "belangenconflict".

## Out of Scope (this member)

UI for the future-scheduling suggestion + conflict registration button surface in members 07–08.

## Dependencies

- **mandaat-matrix-02-authorization-engine** (REQUIRED) — isAuthorized to extend
- **mandaat-matrix-05-case-decision-integration** (REQUIRED) — enforcement + audit log
- **BRP register set** (REQUIRED for automatic conflict) — relationship lookup
