## Why

A signed beschikking is a rechtshandeling with a bezwaartermijn attached. In a bezwaar, in
court or in an audit, "the decision now says X" and "the decision said Y when it was served"
have to be separable. Today they are not, for two reasons that compound.

The rule already exists, and it is real as far as it goes.
`BeschikkingService::updateFields()` refuses any of seven content fields once the status is
`signed`, `sent`, `received-confirmation` or `archived`, and `BeschikkingController` turns that
into a 409. Reading the PATCH route alone, the decision looks frozen.

But that method is the only enforcement, and it is not the only door. The beschikking is an
OpenRegister object, so OpenRegister's own object API will PATCH or DELETE it for any
authenticated caller without ever entering dossiq's service. dossiq already knows this: the
`BewijsstukImmutabilityListener` docblock on `development` spells it out, that a rule with no
call site on the persistence path is "identical to having no check at all", and both
bewijsstuk and checklistRun are guarded by pre-persist listeners for exactly that reason.
`beschikking` was simply never added to them. Delete had no guard at any layer, and the
controller exposes no DELETE route, so deleting a signed decision was only ever possible
through the unguarded door.

And the door itself is bricked up. `beschikking` is imported into OpenRegister but its slug is
absent from `SchemaSlugMap::SLUG_TO_CONFIG_KEY`, so `SchemaKeyReconciler` never writes
`beschikking_schema`, so `BeschikkingRepository::save()` throws `beschikking_schema_not_configured`
on every call. The same holds for `state_machine_log_schema`, `bezwaar_trigger_schema` and
`mandaat_regeling_schema`. Four services read config keys that nothing writes. The whole
beschikking lifecycle is unreachable at runtime.

The audit trail is weaker than it reads. OpenRegister does seal per-property `old`/`new` diffs
into a SHA-256 hash chain, so a tampered row is detectable, and that part is genuine. What it
does not hold is a snapshot of any earlier version: only a diff, and four things eat into
replaying one. `AuditTrailMapper::revertObject()` is the app's single reconstruction path and
it cannot run at all, because `revertChanges()` calls `$audit->getChanges()` while the entity's
property is `changed`, so `Entity::__call` throws and the route answers 500. Any value above
64 KB is dropped from the diff permanently. A permanent delete returns before the audit block
and writes no row whatsoever. And some update rows are written with a null `old`, which records
the object as having come from nothing.

So "we could always reconstruct what was served" does not hold. What contains this case is
narrower and much more specific, and it is the bricked-up door above: because
`beschikking_schema` was never configured, no beschikking has ever been persisted, so there is
no served version to have lost. This is a window, not a loss, and it is open only until the
lifecycle is made reachable, which is the first thing this change does.

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
