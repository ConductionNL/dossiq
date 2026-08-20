# Proposal: bag-location-save-validation

## Why

`bag-register-adapter` shipped `BagAdapterInterface` (live Kadaster BAG API Individuele
Bevragingen v2 lookup, dormant `LogBagAdapter` default) and documented the `location` schema's
`source: bag` / `nummeraanduidingId` fields as its "not-yet-wired enrichment hook" (see
`openspec/changes/archive/2026-07-13-bag-register-adapter/design.md` Decision 3). Its `tasks.md`
item 4.1 filed the follow-up explicitly: *"Wire `location.source=bag` / `nummeraanduidingId`
validation against `BagAdapterInterface::lookupObject()` in the case/location save path... File
as a separate change."*

Today the `location` schema (`lib/Settings/procest_register.json`) declares `nummeraanduidingId`
as "MUST be present when source = bag" in its description only — there is zero enforcement. Any
location can be saved with `source: "bag"` and no `nummeraanduidingId`, or a syntactically
malformed one, and OpenRegister's JSON-schema hard validation (which is presence/type-only, not
conditional-on-sibling-field) will not catch it.

## What Changes

- **REQ-BLV-001**: Add `LocationBagValidationListener`, hooked onto OpenRegister's pre-persist
  `ObjectCreatingEvent` / `ObjectUpdatingEvent` (`StoppableEventInterface`) — the same generic
  object-save event family every other procest cross-field save-guard uses (sibling pattern:
  `ChecklistRunImmutabilityListener` on `ObjectUpdatedEvent`). Scoped to the `location` schema via
  the existing `SettingsService::getConfigValue('location_schema')` bridge.
- **REQ-BLV-002**: When a `location` payload has `source = bag`, `nummeraanduidingId` MUST be
  present and match the 16-digit BAG identificatie shape (`^\d{16}$`); otherwise the save is
  rejected pre-persist (`stopPropagation()` + a translated error message) — the invalid row never
  reaches the database, unlike a post-persist listener.
- **REQ-BLV-003**: When the format is valid and `BagAdapterInterface` is non-dormant (config-tier
  resolves to `test`/`live`), attempt a best-effort existence check via
  `BagAdapterInterface::lookupObject('nummeraanduiding', $id)`. A definitive `NOT_FOUND` rejects
  the save with a translated error; every other outcome (dormant/log-mode adapter, transport
  error, `LOOKUP_DEFERRED`) accepts the save with a logged warning rather than rejecting it — the
  adapter's own fail-soft contract is mirrored, not turned into a hard dependency for every
  location save.
- **REQ-BLV-004**: Extend `BagApiAdapter::lookupObject()`'s supported object types with
  `nummeraanduiding` → `/nummeraanduidingen/{id}` (additive; `pand`/`verblijfsobject` unchanged),
  since the existence check in REQ-BLV-003 needs it and the port did not support it yet.

## Capabilities

### New Capabilities

- `bag-location-save-validation`: save-path enforcement of the `location` schema's `source: bag`
  ⇒ `nummeraanduidingId` contract, with optional BAG existence verification.

## Impact

- **Backend**: new `lib/Listener/LocationBagValidationListener.php`; new DI registration in
  `lib/AppInfo/Application.php` (`ObjectCreatingEvent` / `ObjectUpdatingEvent` →
  `LocationBagValidationListener`); small additive change to
  `lib/Service/External/Bag/BagApiAdapter.php` (`OBJECT_PATHS` map + docblocks); two new
  translated strings (`l10n/en.json` + `l10n/nl.json`).
- **Frontend**: none — this is a save-path (server-side) guard; no existing case-detail UI panel
  reads/writes `location.source`/`nummeraanduidingId` yet (confirmed by `bag-register-adapter`
  Decision 3; still true).
- **Dependencies**: none added.
