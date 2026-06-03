---
kind: code
depends_on: [mandaat-matrix-02-authorization-engine]
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

# Proposal: Mandaat-matrix — Member 03: Escalation Engine (code)

Member **3 of 9** in the `mandaat-matrix` chain. Predecessor:
`mandaat-matrix-02-authorization-engine`. This member implements the escalation engine:
`MandaatEscalatieService` (creation, next-higher-mandaat path resolution, auto-rerouting on
personnel change) and `EscalatieApprovalService` + `MandaatEscalatieController` (approve /
reject / list / detail). It consumes the verdict produced in member 02 and the schemas from
member 01.

## Why

When a user lacks authority (niet_bevoegd, plafond_overschreden, subdelegatie, belangenconflict),
the decision must be auto-routed to the correct mandaathouder with notification, then approved or
rejected — with the audit trail recording the outcome. Personnel changes must reroute open
escalaties without manual intervention.

## What Changes

1. **`MandaatEscalatieService`** — `createEscalatie()`, `resolveEscalatiePath()`,
   `autoRerouteOnPersonnelChange()`, notification dispatch.
2. **`EscalatieApprovalService`** — `approveEscalatie()`, `rejectEscalatie()`.
3. **`MandaatEscalatieController`** — `POST .../approve`, `POST .../reject`, `GET` list + detail.

## Out of Scope (this member)

The decision-flow listener that *triggers* escalation lives in member 05; MandaatGebruik logging
on approval is invoked from here but the immutable-log service is owned by member 05. Belangenconflict
as an escalation reason is wired in member 06.

## Dependencies

- **mandaat-matrix-02-authorization-engine** (REQUIRED) — verdict + reden values
- **mandaat-matrix-01-schema-foundation** (REQUIRED) — MandaatEscalatie schema
