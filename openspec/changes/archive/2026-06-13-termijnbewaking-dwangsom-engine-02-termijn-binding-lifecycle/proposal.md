---
kind: code
depends_on: [termijnbewaking-dwangsom-engine-01-schemas-and-seed]
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

# Proposal: termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle

Member 2 of 11 in the **termijnbewaking-dwangsom-engine** chain (ADR-032). Predecessor: `termijnbewaking-dwangsom-engine-01-schemas-and-seed`. This `kind: code` member consumes the schemas declared in member 01 to bind a `TermijnInstance` to every zaak at creation, calculate the deadline, record the immutable start event, and block zaak-creation when no matching `TermijnDefinitie` exists.

## Why

The deadline engine is worthless if cases silently slip through without a termijn attached. AWB 4:13 requires every decision to have a known statutory deadline; explicit configuration (block-on-missing-definition) prevents silent deadline-handling failures. This member wires the zaak-creation hook to the declarative schemas so every case is deadline-aware from the moment it is created.

## What Changes (this member)

1. `TermijnService` with OpenRegister `ObjectService`-backed CRUD for `TermijnInstance` (create, get, update) and cached `getTermijnDefinitie()`.
2. Zaak-creation hook auto-creates a `TermijnInstance` (status `lopend`, `einddatumBerekend`, start `TermijnGebeurtenis`).
3. Block zaak-creation with an admin-facing error when the zaaktype has no `TermijnDefinitie`.
4. `TermijnDefinitie` versioning semantics: existing instances keep their original `einddatumBerekend`; new cases use the latest version.

## Impact

- **Affected**: procest (`TermijnService`, zaak-creation hook).
- **Traces to giant tasks**: Task 2 (service CRUD + OpenRegister client), Task 1 binding behaviour (REQ-TERM-001-A/B/C).
- **Depends on**: member 01 schemas + seed.
