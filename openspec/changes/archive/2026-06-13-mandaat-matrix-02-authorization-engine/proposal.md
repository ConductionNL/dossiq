---
kind: code
depends_on: [mandaat-matrix-01-schema-foundation]
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

# Proposal: Mandaat-matrix — Member 02: Authorization Engine (code)

Member **2 of 9** in the `mandaat-matrix` chain. Predecessor:
`mandaat-matrix-01-schema-foundation`. This member implements the real-time
bevoegdheidscheck — `MandaatCheckService` (role resolution incl. waarnemer, condition
evaluation: plafond + subdelegatie) and the `AbacPolicyService` wrapper that delegates
condition evaluation to OpenRegister's ABAC policy engine. It reads the schemas declared in
member 01.

## Why

Authorization must be enforced on every delegated decision in <100ms, replacing manual mandate
lookups. This member is the decision core: given a user, a decision type, and a case, it returns
whether the user is authorized, which mandate applies, and the reason on denial.

## What Changes

1. **`MandaatCheckService`** — `isAuthorized()`, `resolveUserRole()` (incl. waarnemer),
   `evaluateConditions()`, `getApplicableMandaten()`.
2. **`AbacPolicyService`** — wrapper around OpenRegister's policy engine for condition evaluation.

## Out of Scope (this member)

Escalation creation (member 03), import (04), decision-flow wiring + MandaatGebruik logging (05),
temporal/conflict (06), UI (07–08). This member returns a verdict; it does not create escalations
or log usage.

## Dependencies

- **mandaat-matrix-01-schema-foundation** (REQUIRED) — schemas + seed data
- **openregister abac-policy-engine** (REQUIRED) — condition evaluation
