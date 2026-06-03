# Proposal: migrate-status-engine-to-or-lifecycle

> REVERTED 2026-06-01: archived prematurely; only the schema `x-openregister-lifecycle` blocks (Voorstel/Parafeerroute/Bezwaar) were actually added — re-opened for real apply. PENDING: PHP guard classes (`lib/Lifecycle/VoorstelSubmitGuard.php`, `HoorzittingAfzienGuard.php`, `BezwaarDeadlineGuard.php` — the `lib/Lifecycle/` directory does not exist), `STATUS_*` constant removal (still present in `ParafeerRouteService`), `x-openregister-hooks`, and the `tests/Unit/Lifecycle/` suite. Schema-block tasks (P-1.1/P-2.1/P-3.1) remain checked; all guard/cleanup/hook/test tasks un-checked.

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
