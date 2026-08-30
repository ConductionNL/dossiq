# Proposal: migrate-parafering-to-or-approval-workflow

> REVERTED 2026-06-01: archived prematurely; implementation not present on development — re-opened for real apply. (No `ParaferingService`/`ApprovalService`/`ApprovalChain` delegation exists in `lib/`; the bespoke step-routing state machine in `ParafeerRouteService`/`ParafeerActieService` is still in place.)

## Why

Procest ships a full step-routing engine for parafering (signing routes): `ParaferingController`,
`ParaferingService`, and `ParaferingNotificationService` implement ordered steps, role-gated
decisions, advance-on-approval logic, and a `Parafeerroute` schema persisted in OR. This is
a bespoke approval-chain engine.

OpenRegister has shipped `approval-workflow` (status: implemented). It provides exactly the
same abstraction: named chains with ordered steps, each step bound to a Nextcloud group,
`pending`/`waiting`/`approved`/`rejected` state transitions, automatic advance-on-approval,
full decision history, and a REST API.

Maintaining a parallel approval-chain engine in procest violates **ADR-022** (Apps Consume
OpenRegister Abstractions). The concrete harms:

- **Duplicate role-gating logic**: procest re-implements `IUserSession` group-membership checks
  that OR's `approval-workflow` already provides and tests.
- **Drift risk**: the parafeerroute step engine evolves independently — edge cases (delegation,
  skip, resubmit) accumulate divergent handling.
- **No cross-app approval queries**: a manager cannot ask "all pending steps across apps"
  without a single OR approval store.
- **Orphaned code**: three service classes (`ParaferingService`, `ParaferingNotificationService`,
  `ParaferingController`) that replicate what OR provides — maintenance surface with no
  architectural benefit.

## What

This spec migrates procest's parafering implementation to use OR's `approval-workflow` API as
the chain-state backend, while fully preserving the existing procest API surface for callers:

1. `ParaferingService` is rewritten to create and manage `ApprovalChain` objects in OR via OR's
   approval-workflow API (or the equivalent OR DI class), translating parafeerroute concepts
   into ApprovalChain terms.
2. `ParaferingNotificationService` is updated to listen on OR's `ApprovalStep` events instead of
   parafeer-local events.
3. `ParaferingController` endpoint surface is **unchanged** — callers continue to use procest's
   parafering endpoints; the controller internally delegates to the rewritten `ParaferingService`.
4. The `Parafeerroute` schema in `procest_register.json` is marked deprecated; no new rows are
   written after migration; existing rows remain readable until sunset.

The existing procest specs (`parafering-actions`, `parafering-audit-trail`, `role-based-step-routing`)
remain as the consumer-facing contract surface and are not modified. The migration is
server-side only.

## Capabilities

### New Capabilities

- `parafering-via-or-approval`: Parafering chains are now backed by OR's `ApprovalChain` entity,
  giving procest access to OR's role enforcement, advance-on-approval, decision history, and
  cross-app approval queries for free.

### Modified Capabilities

- `parafering-actions` (spec: `procest/openspec/specs/parafering-actions/spec.md`) —
  consumer-facing contract unchanged; implementation now routes through OR's approval-workflow API.
- `role-based-step-routing` (spec: `procest/openspec/specs/role-based-step-routing/spec.md`) —
  step-role enforcement is now performed by OR's `approval-workflow` role check; the spec
  surface for callers is unchanged.

## Affected Projects

- [x] Project: `procest` — all implementation tasks are in this repo
- [x] Project: `openregister` — no code change; verify DI class for ApprovalChain CRUD (tracked
  in umbrella OR-1.1)

## Out of Scope

- Procest's parafering UX/frontend: the migration is server-side only.
- Parafering audit trail migration (covered by `migrate-parafering-to-or-audit`).
- Modifying OR's `approval-workflow` spec or API.
- Historical backfill of existing `Parafeerroute` rows into OR's ApprovalChain tables.
- Modifying `parafering-actions`, `parafering-audit-trail`, or `role-based-step-routing` specs.

## Success Criteria

- `openspec validate --strict migrate-parafering-to-or-approval-workflow` exits 0.
- `GET /api/approval-chains` returns procest parafering chains after migration.
- Existing procest parafering API tests pass without modification.
- No new `Parafeerroute` objects are created in OR after the migration ships.
- `ParaferingService`, `ParaferingController`, and `ParaferingNotificationService` contain
  no bespoke step-routing state-machine code.
