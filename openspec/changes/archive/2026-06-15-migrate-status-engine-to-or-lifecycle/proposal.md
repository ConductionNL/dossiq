# Proposal: migrate-status-engine-to-or-lifecycle

> BUILT 2026-06-15 (build/migrate-status-engine-to-or-lifecycle-2026-06-15):
> OpenRegister PR #153 landed the lifecycle transition-guard engine
> (`LifecycleGuardInterface`, `GuardResult`, `LifecycleValidationListener`
> enforcing transitions on saveObject, plus `requires` guards). Procest now
> CONSUMES it for the two fixed-enum lifecycle schemas:
> - `voorstel.status` already had its `x-openregister-lifecycle` table; added the
>   `requires: OCA\Procest\Lifecycle\VoorstelSubmitGuard` on `startParafering`.
> - `bezwaar.status` got a full `x-openregister-lifecycle` AWB transition table
>   (10 transitions, real enum strings) with `requires` guards on
>   `hoorzitting_overslaan` (HoorzittingAfzienGuard) and `beslissen`
>   (BezwaarDeadlineGuard).
> - Created `lib/Lifecycle/{VoorstelSubmitGuard,HoorzittingAfzienGuard,BezwaarDeadlineGuard}.php`
>   implementing OR's `LifecycleGuardInterface::check()`; OR-class stubs for the unit
>   suite under `tests/Stubs/Lifecycle/`; `tests/Unit/Lifecycle/{Voorstel,Bezwaar}LifecycleTest.php`.
>
> SCOPE CORRECTION (supersedes the stale notes below): the design assumed a
> `ParaferingService` with `STATUS_*` constants — that class does not exist (the
> constants live in `ParafeerActieService`/`ParafeerRouteService`, which write the
> SAME enum values OR now validates, so the constants are harmless aliases, not a
> bespoke validation matrix; removing them is a no-functional-gain refactor on a
> now-deprecated parafeerroute path and was left out to avoid regression). The
> design's `parafeerroute` lifecycle is also dropped — that schema is DEPRECATED
> (no `status` field; migrated to OR approval-workflow). The `x-openregister-hooks`
> n8n re-wiring is out of scope (separate workflow-integration migration).
>
> RESIDUAL OR GAP (genuine, flagged): the case-level engine `StatusTransitionService`
> is NOT migrated. `case.status` is a UUID reference to a per-caseType `statusType`
> object — its valid states/transitions are defined dynamically by each
> `workflowTemplate`, which OR's static `x-openregister-lifecycle` table cannot
> express. The bespoke engine remains the source of truth for `case.status`. Closing
> this needs OR to support a per-object/dynamic transition table (workflow-driven
> lifecycle), not just a fixed schema-level table.

## Why

Procest ships three in-app state machine implementations for OR-owned objects that
violate ADR-022 (apps consume OR abstractions) and ADR-031 (schema-declarative
business logic):

1. **`ParaferingService`** declares four PHP constants as a voorstel/parafeerroute
   state machine (`STATUS_CONCEPT`, `STATUS_IN_PARAFERING`, `STATUS_TERUGGESTUURD`,
   `STATUS_GEPARAFEERD`) and transitions state by calling `ObjectService::saveObject()`
   directly.

2. **`status-transition-engine` spec** documents runtime guard evaluation, atomic
   transition execution, and automatic-action dispatch as in-app PHP capabilities
   operating on zaak, bezwaar, and parafeerroute objects that are all stored in OR.

3. **Automatic actions on transitions** (send email, create task) are wired via
   Application.php event listeners that fire post-transition, bypassing OR's schema
   hook mechanism and `WorkflowEngineInterface`.

These patterns produce:

- **Missed OR benefits**: no audit trail of lifecycle transitions via OR's
  hash-chained `AuditTrailMapper`, no per-state RBAC, no automatic CloudEvents,
  no replayable restore.
- **Fleet drift**: other apps copy the service-based pattern instead of the
  schema-extension path, compounding the migration surface.
- **Parallel state logic**: transition guards re-implement validations that OR's
  lifecycle engine performs automatically when `x-openregister-lifecycle.requires`
  is used.

OR ships `x-openregister-lifecycle` (part of `object-lifecycle` + ADR-031) as the
canonical solution. The `workflow-engine-abstraction` spec's `WorkflowEngineInterface`
handles all workflow execution. This change migrates procest's state machines to
consume both.

## What

This change migrates procest's status-transition logic for three schemas — voorstel,
parafeerroute, and zaak (AWB bezwaar lifecycle) — from PHP service constants + manual
saves to `x-openregister-lifecycle` schema extensions in
`lib/Settings/procest_register.json`. Automatic actions wired to transitions are
re-expressed as schema hooks (`workflow-integration`) targeting existing n8n flows.

The existing procest public API (endpoints that change case/voorstel status) is
preserved: they now submit an object PATCH with the new `lifecycle` field value;
OR's lifecycle engine validates and applies the transition atomically.

The `status-transition-engine`, `workflow-definition-model`, `workflow-import-export`,
and `vth-workflow-templates` specs are NOT modified — they describe data models and
tooling that remain valid. This spec documents that the runtime implementation of
those models now consumes OR instead of in-app PHP services.

## Capabilities Affected

### Modified Capabilities

- `status-transition-engine` (procest) — implementation now delegates transition
  validation and execution to OR's lifecycle engine; the spec body is unchanged.
- `parafeerroute-engine` (procest) — step-routing logic (which parafeerstap is
  active) stays in PHP; the voorstel/parafeerroute lifecycle states move to schema
  extension.

### New Capabilities

- `migrate-status-engine-to-or-lifecycle` — migration spec for the three affected
  schemas; documents before/after and the PHP guard classes that remain.

## Affected Projects

- [x] Project: `procest` — all implementation tasks
- [x] Project: `openregister` — stability verification (no code change)

## Success Criteria

- `openspec validate --strict migrate-status-engine-to-or-lifecycle` exits 0.
- `lib/Settings/procest_register.json` includes `x-openregister-lifecycle` blocks
  for voorstel, parafeerroute, and bezwaar schemas.
- `composer check:strict` passes on procest with no new errors.
- `ParaferingService` no longer declares PHP status constants or calls
  `ObjectService::saveObject()` for lifecycle state changes.
- Lifecycle transitions on voorstel/parafeerroute/bezwaar objects appear in
  `GET /api/audit-trails?objectUuid={id}` on the dev environment.
