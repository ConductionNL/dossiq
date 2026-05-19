# Design: migrate-parafering-to-or-audit

## Context

The procest parafering audit trail currently flows through a parallel pipeline:

```
ParafeerTransitionEvent
  → ParaferingAuditListener
    → ObjectService::saveObject(register, paraferingAuditEntry_schema, entry)
      → OR stores the record as a regular OR object (not an audit-trail entry)
```

The `ParaferingAuditAppendOnlyValidator` then blocks UPDATE/DELETE on those objects via
OR's object lifecycle events to simulate immutability.

After migration, the flow MUST be:

```
ParafeerTransitionEvent
  → ParaferingAuditListener (rewritten)
    → AuditTrailMapper::createAuditTrailEntry(ObjectEntity $object, string $action, array $context)
      → OR audit trail (hash-chained, natively immutable)
```

## File-by-File Migration Plan

### lib/Listener/ParaferingAuditListener.php — REWRITE

**Current**: injects `ObjectService` and `IAppConfig`; reads `paraferingAuditEntry_register`
and `paraferingAuditEntry_schema` from config; calls `objectService->saveObject()`.

**After**: inject `OCA\OpenRegister\Db\AuditTrailMapper`. On each `ParafeerTransitionEvent`:

1. Determine the transition name from the event (e.g. `approved`, `returned`, `skipped`).
2. Build the action type: `procest.parafering.{transitionName}`.
3. Build the `$context` array (persisted in the `changed` JSON column):
   ```php
   $context = [
       'parafeerrouteId'  => $event->getParafeerrouteId(),
       'paraffeerstapId'  => $event->getParaffeerstapId(),
       'fromState'        => $event->getFromState(),
       'toState'          => $event->getToState(),
       'actorUuid'        => $event->getActorUuid(),
       'comment'          => $event->getComment() ?? null,
   ];
   ```
4. Call `AuditTrailMapper::createAuditTrailEntry($object, $actionType, $context)` where
   `$object` is the `ObjectEntity` for the parafeerroute.

App-specific context is carried in the `$context` array argument to
`AuditTrailMapper::createAuditTrailEntry()`, which is persisted in the existing `changed`
JSON column on the `openregister_audit_trails` table. No OR schema change is required.

### lib/Validator/ParaferingAuditAppendOnlyValidator.php — REMOVE

This file is deleted entirely. OR's audit trail is append-only by construction (HTTP 405
on PUT/DELETE on `/api/audit-trails/{id}`). No replacement is needed.

### lib/AppInfo/Application.php lines 127–147 — UPDATE

Remove the registrations for `ObjectCreatingEvent`, `ObjectUpdatingEvent`, and
`ObjectDeletingEvent` that target `ParaferingAuditAppendOnlyValidator`. The
`ParaferingAuditListener` registration (for `ParafeerTransitionEvent`) is retained and
updated to use the new listener implementation.

### lib/Settings/procest_register.json — MARK DEPRECATED

The `paraferingAuditEntry` schema entry remains in the JSON to keep existing rows readable.
Add a `deprecated: true` flag and a `deprecationNote` string:

```json
"paraferingAuditEntry": {
  "deprecated": true,
  "deprecationNote": "Superseded by OR audit trail via migrate-parafering-to-or-audit. Sunset: one major release after spec acceptance. No new writes after migration.",
  ...existing schema fields...
}
```

Do not remove the schema object — existing rows must remain readable until sunset.

### openspec/specs/parafering-audit-trail/spec.md — UPDATE (apply phase)

During the apply phase (not now), update the existing `parafering-audit-trail` spec to
reference the new consumer contract: "audit trail is accessible via
`GET /api/audit-trails?objectUuid={parafeerrouteId}`". The existing spec's requirements for
immutability, delegation display, and export remain valid but the discovery mechanism changes.

## Backwards Compatibility

- Existing `paraferingAuditEntry` rows in OR remain queryable via the deprecated schema
  endpoint for one major release (sunset date in proposal.md).
- New parafering transition events ONLY emit via OR audit trail after this spec ships.
- The consumer contract (callers asking "what happened to parafeerroute X") is preserved
  through the new OR audit-trail discovery endpoint.

## Event Type Naming Convention

Transition names follow the parafeerroute state machine. The listener maps transition names
from `ParafeerTransitionEvent` to action type strings:

| Transition | Action type |
|---|---|
| approved | `procest.parafering.approved` |
| returned | `procest.parafering.returned` |
| skipped | `procest.parafering.skipped` |
| delegated | `procest.parafering.delegated` |
| advised | `procest.parafering.advised` |
| accorded | `procest.parafering.accorded` |

If the event carries a transition name not in this table, the listener MUST use the raw name
as `procest.parafering.{rawName}` (no exception, no fallback to `unknown`).

## Seed Data

No new schemas are added. The `paraferingAuditEntry` schema is deprecated in-place. No
new registers or register definitions are created by this migration.

## Related ADRs

- **ADR-022** (primary) — mandate for this migration.
- **ADR-008** — testing contract; hash-chain verification test required.
- **ADR-001** — data layer; no new entities or mappers introduced.
