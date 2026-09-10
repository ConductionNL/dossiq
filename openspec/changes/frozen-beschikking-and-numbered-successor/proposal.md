## Why

A signed beschikking is a rechtshandeling with a bezwaartermijn attached. In a bezwaar, in
court or in an audit, "the decision now says X" and "the decision said Y when it was served"
have to be separable. Today they are not, for two reasons that compound.

The rule already exists. `REQ-BES-008` says a beschikking at `signed` or later may not be
edited substantively. But the only code that enforces it is
`BeschikkingService::updateFields()`, one method behind one route. Under ADR-022 the frontend
writes objects through OpenRegister's generic object API, which never reaches that method. So
the guard covers the door nobody uses.

And the door itself is bricked up. `beschikking` is imported into OpenRegister but its slug is
absent from `SchemaSlugMap::SLUG_TO_CONFIG_KEY`, so `SchemaKeyReconciler` never writes
`beschikking_schema`, so `BeschikkingRepository::save()` throws `beschikking_schema_not_configured`
on every call. The same holds for `state_machine_log_schema`, `bezwaar_trigger_schema` and
`mandaat_regeling_schema`. Four services read config keys that nothing writes. The whole
beschikking lifecycle is unreachable at runtime.

The history is not lost. OpenRegister records per-property `old`/`new` diffs sealed into a
SHA-256 hash chain, and `AuditTrailMapper::revertObject()` reconstructs a point-in-time state.
So this is "we cannot show what was served", not "we cannot recover it". That is a smaller
problem than it looked, and it is still a real one: reconstruction runs through a revert that
writes, values above 64 KB are elided from the diff, and no surface names the served version.

## What Changes

- Wire the four beschikking schema keys into `SchemaSlugMap` so the reconciler configures them,
  and into the settings allowlist so an admin can see them. The beschikking lifecycle becomes
  reachable.
- Add `BeschikkingImmutabilityListener`, a pre-persist guard on OpenRegister's
  `ObjectUpdatingEvent` and `ObjectDeletingEvent`, registered from the existing
  `ImmutabilityListenerRegistrar`. It refuses a content edit or a delete on a beschikking at
  `signed` or later, whichever door the write came through. The stored state decides, not the
  incoming payload.
- Move the immutable-field list and the refusal into `StateMachineService`, next to the
  statuses that freeze a beschikking, and have both the PATCH route and the listener call it.
  Two halves of one rule drift when they live apart, and the drift is silent.
- **BREAKING for direct object writers**: a PATCH or a delete on a signed beschikking through
  OpenRegister's object API now fails. That is the point of the change, and it is the behaviour
  `REQ-BES-008` has specified since the beschikking-generatie change landed.
- Specify the correction as a numbered successor. A correction becomes a new beschikking that
  carries the original's id and its own number, and the original stays untouched. The spec is
  written; the implementation is deliberately not, because the numbering and the withdrawal
  rule are product decisions. See `design.md`.

## Capabilities

### New Capabilities

None. Both requirements belong in the existing beschikking spec.

### Modified Capabilities

- `beschikking-generatie`: `REQ-BES-008` moves from a service-method rule to a persistence
  guard and gains the successor relation. A new requirement says a schema slug a service
  resolves must have a config key the reconciler writes.

## Impact

- `lib/Service/Settings/SchemaSlugMap.php`: four new slug mappings.
- `lib/Service/Settings/ConfigKeys.php`: new. The 200-entry allowlist moves out of
  `SettingsService`, which sat exactly on the phpmd `ExcessiveClassLength` ceiling, so no new
  key could be added without reddening the tree. `development` was already failing that leg;
  this fixes it.
- `lib/Listener/BeschikkingImmutabilityListener.php`: new, modelled on
  `BewijsstukImmutabilityListener`.
- `lib/AppInfo/Registrar/ImmutabilityListenerRegistrar.php`: one more registration pair.
- `lib/Service/StateMachineService.php`: gains `assertMutable()` and `assertDeletable()`.
- `lib/Service/BeschikkingService.php`: `updateFields()` delegates to the state machine.
- `tests/Unit/Service/Settings/SchemaKeyCoverageTest.php`: new. Pins the seven remaining
  unreconciled schema keys so an eighth cannot be added quietly.
- `tests/Unit/Listener/BeschikkingImmutabilityListenerTest.php`: new.
- No schema property is added. A property nothing writes reads like a shipped feature from the
  outside, and the successor write path is not being built in this change.
- Runtime: an instance that has never had `beschikking_schema` set gets it on the next
  reconcile. Nothing depended on the old empty value, because every read of it threw.
