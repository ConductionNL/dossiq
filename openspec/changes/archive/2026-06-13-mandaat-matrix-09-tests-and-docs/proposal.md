---
kind: code
depends_on: [mandaat-matrix-08-user-ui]
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

# Proposal: Mandaat-matrix — Member 09: Tests, @spec Tags, and Documentation (code)

Member **9 of 9** (final) in the `mandaat-matrix` chain. Predecessor: `mandaat-matrix-08-user-ui`.
This member hardens the completed feature: unit tests for `MandaatCheckService`, integration tests
for the escalation workflow and the case-decision authorization guard, `@spec` docblock tags +
architectural-compliance review across the new classes, and admin documentation (import,
role-hierarchy, waarnemer, troubleshooting).

## Why

The feature is only complete when its behaviour is verified end-to-end (authorized / denied /
escalated / waarnemer / temporal), every public method traces to a REQ via `@spec`, and admins have
a step-by-step guide. This member closes the chain (ADR-008 testing, ADR-009 documentation).

## What Changes

1. **Unit tests** — `MandaatCheckServiceTest` (role/mandate combos, waarnemer, plafond, subdelegatie,
   temporal).
2. **Integration tests** — escalation workflow (create → approve/reject → reroute); case-decision
   authorization guard (authorized/denied/waarnemer, MandaatGebruik logged).
3. **@spec tags + compliance review** across the new service classes; CLAUDE.md context update.
4. **Admin documentation** — `docs/user/mandate-matrix-admin.md`.

## Out of Scope (this member)

No new feature behaviour — verification, annotation, and documentation only.

## Dependencies

- **mandaat-matrix-02..08** (REQUIRED) — all behaviour under test
