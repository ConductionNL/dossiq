# Proposal: frozen record capability

## Why

Decision 182: procedures are configuration, code is generic. Capability C04 in hydra's
`procedures-are-configuration` design is "frozen record: seals a set of documents or fields at a
transition, so it can be verified later by hash, with a numbered successor". Today each procedure
has its own copy: `BeschikkingImmutabilityListener` with the rule in `StateMachineService`,
`BewijsstukImmutabilityListener`, and the Woo delivered set in #3578 and #3598.

## What changes

- `Service/FrozenRecord/FreezeDefinition`, `FreezeRegistry` (entries from app config `frozen_records`),
  `FrozenRecordGuard`, `RecordSeal`, `FrozenRecordSuccessor` (numbers through `DocumentSeriesNumberer`, #3624).
- `Listener/FrozenRecordListener`, registered on OpenRegister's updating and deleting events in
  `ImmutabilityListenerRegistrar`. With nothing configured it guards nothing.
- Spec `frozen-record`: REQ-FRZ-001 to REQ-FRZ-004.

Nothing moves yet: the beschikking, bewijsstuk and Woo delivered set move onto this capability in
later R5 PRs, once the build lanes' open PRs on those files have landed.

## Impact

New classes, unit-tested on a neutral fixture record kind. No route, no screen, no migration. One
app-config read per object update and delete.
