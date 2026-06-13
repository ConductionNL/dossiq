---
kind: code
depends_on: [termijnbewaking-dwangsom-engine-10-bezwaar-rest-api]
chain:
  - termijnbewaking-dwangsom-engine-01-schemas-and-seed
  - termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle
  - termijnbewaking-dwangsom-engine-03-pause-extension
  - termijnbewaking-dwangsom-engine-04-daily-scan-escalation
  - termijnbewaking-dwangsom-engine-05-ingebrekestelling
  - termijnbewaking-dwangsom-engine-06-dwangsom-calculation
  - termijnbewaking-dwangsom-engine-07-financial-integration
  - termijnbewaking-dwangsom-engine-08-burger-notifications
  - termijnbewaking-dwangsom-engine-09-reporting-dashboard
  - termijnbewaking-dwangsom-engine-10-bezwaar-rest-api
  - termijnbewaking-dwangsom-engine-11-tests-admin-docs
---

# Proposal: termijnbewaking-dwangsom-engine-11-tests-admin-docs

Member 11 of 11 (final) in the **termijnbewaking-dwangsom-engine** chain (ADR-032). Predecessor: `termijnbewaking-dwangsom-engine-10-bezwaar-rest-api`. This `kind: code` member adds the end-to-end test scenarios, the admin TermijnDefinitie configuration UI, and the admin/user documentation that close out the feature.

## Why

The chain is only "done" when the full workflow is verified end-to-end (normal, pause/resume, extension, overschrijding+dwangsom, bezwaar), admins can configure TermijnDefinities through a UI (not just seed JSON), and handlers/admins have guides. This member is the verification + operability cap on the whole engine; per-member unit/integration tests already exist, so this focuses on cross-cutting e2e scenarios + the admin surface.

## What Changes (this member)

1. End-to-end workflow test scenarios covering the five lifecycle paths.
2. Cross-service integration test sweep (zaak-creation → ... → payment) consolidating the per-member coverage.
3. Admin TermijnDefinitie configuration UI (Vue) with versioning semantics.
4. Admin guide + user guide (Dutch).

## Impact

- **Affected**: procest (`tests/Feature`, `tests/Integration`, `src/views/admin/TermijnDefinitiesTab.vue`, `src/components/TermijnDefinitieEditor.vue`, `docs/`).
- **Traces to giant tasks**: Task 21 (unit-test consolidation), Task 22 (integration tests), Task 23 (e2e scenarios), Task 24 (admin UI), Task 25 (documentation).
- **Depends on**: member 10 (full REST surface to test against).
