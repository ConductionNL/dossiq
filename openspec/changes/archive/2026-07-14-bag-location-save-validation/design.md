# Design: bag-location-save-validation

## 1. Where to hook — the pre-persist event pair, not the post-persist pair

Procest has two families of OpenRegister object-save hooks available:

- **Post-persist** (`ObjectCreatedEvent` / `ObjectUpdatedEvent`) — dispatched by
  `MagicMapper::insert()`/`update()` **after** the row is already written
  (`lib/Db/MagicMapper.php`, no surrounding DB transaction). `ChecklistRunImmutabilityListener`
  uses this family to reject illegal *mutations* of an already-frozen row by throwing
  `RuntimeException` — acceptable there because the check is about a state the row was already in,
  not about rejecting the row's very first write.
- **Pre-persist** (`ObjectCreatingEvent` / `ObjectUpdatingEvent`, both `StoppableEventInterface`) —
  dispatched by `MagicMapper::insertObjectEntity()`/`updateObjectEntity()` **before** the DB write
  (`saveObjectToRegisterSchemaTable()` runs strictly after). A listener calls
  `$event->setErrors([...])` + `$event->stopPropagation()`; `insertObjectEntity()` /
  `updateObjectEntity()` then throw `HookStoppedException` *without ever writing the row*.
  `ObjectsController` (OpenRegister) already translates `HookStoppedException` into a clean HTTP
  400 — no procest-side exception mapping needed.

This change uses the **pre-persist** pair. A location that fails `source: bag` validation must
never be persisted at all — using the post-persist pair (like the checklist-immutability listener)
would let the invalid row land in the database and only surface an error on the *response*,
leaving an orphaned bad row behind. No procest listener uses `ObjectCreatingEvent` /
`ObjectUpdatingEvent` yet; this change is the first, but it is the *correct* member of the
existing generic-object-save-event family, not a parallel path — every schema in this app (case,
location, or otherwise) is created/updated through this exact same OpenRegister event pipeline.

## 2. Why not a dedicated `LocationService`

`case-location/spec.md` (REQ-LOC-05/06) documents a `LocationService::validate()` /
`::attachToCase()` write path with an almost-identical rule matrix. That service was deleted in
commit `bac6f7073` (`migrate-cases-on-map-to-maps-overview-leaf`, 2026-06-15) when the map-overview
UI moved to OpenRegister's `maps-overview` surface — the spec was not updated afterward and is
stale (retrofit-annotated onto code that no longer exists). There is currently **no** PHP service
that owns location saves; locations are written through the generic OpenRegister object-save API
directly (confirmed: zero `LocationController`, zero `attachToCase` references at HEAD). Given
that, the only save-path seam available to enforce this rule *without inventing a new bespoke
write path* is the generic OpenRegister event pipeline — which is what this change uses. Filing a
stale-spec cleanup for `case-location` is out of scope here and is called out as a follow-up in
`tasks.md`.

## 3. Existence-check scope: `nummeraanduiding` only, fail-open on ambiguity

`BagAdapterInterface::lookupObject(string $objectType, ...)` only supported `pand` and
`verblijfsobject` before this change (`BagApiAdapter::OBJECT_PATHS`). Neither is the right BAG
object type for a location's `nummeraanduidingId` (a nummeraanduiding is its own BAG object type,
distinct namespace from pand/verblijfsobject identificaties). This change adds
`'nummeraanduiding' => 'nummeraanduidingen'` to `OBJECT_PATHS` — additive, no signature change,
`BagResponseMapper::map()` is already defensive against an unfamiliar fragment shape (every field
extractor null-coalesces, never throws on missing keys), so passing a nummeraanduiding fragment
through the existing mapper is safe even though its shape differs from a pand/verblijfsobject
fragment.

The existence check is deliberately **fail-open**: only a definitive `NOT_FOUND` rejects the save.
- `isDormant() === true` (log-mode, the default): skip verification, save proceeds.
- Transport error / any `Throwable` from the adapter call: log a warning, save proceeds.
- `LOOKUP_DEFERRED` / `LOOKUP_ERROR` / `INVALID_INPUT`: log a warning, save proceeds (only `FOUND`
  and `NOT_FOUND` are decisive outcomes for this port).

This mirrors the adapter's own dormant/fail-soft design goal (`BagLookupResult`'s docblock:
"the surrounding lifecycle stays observable... never contacting Kadaster" when dormant) — a
misconfigured or momentarily-unreachable BAG tier must not become a hard outage for every location
save in the app. Format validation (the 16-digit shape) is the only *hard* gate; existence
verification is best-effort enrichment.

## 4. Error surfacing

`ObjectCreatingEvent`/`ObjectUpdatingEvent::setErrors(['message' => string, 'codes' => string[]])`
+ `stopPropagation()`. `message` is one translated (`IL10N::t()`) sentence for the first applicable
code (required → invalid → unknown, in that priority order, matching the order a caller would want
to fix them); `codes` carries every machine-readable code for programmatic consumers (mirrors the
`case-location` spec's own `validate(): array<string>` code-array convention, REQ-LOC-05).

## 5. Testing boundary

- `LocationBagValidationListenerTest` (unit): the validation matrix (absent source, `source=bag`
  with valid/missing/malformed id, dormant-adapter accept, non-dormant `NOT_FOUND` reject,
  non-dormant `FOUND` accept, non-dormant `LOOKUP_ERROR`/`Throwable` accept-with-warning,
  non-`location`-schema no-op). `BagAdapterInterface` is mocked — no HTTP.
- `BagApiAdapterTest` additions: `lookupObject('nummeraanduiding', ...)` hits
  `/nummeraanduidingen/{id}`, same 404→NOT_FOUND / 2xx→FOUND / other→LOOKUP_ERROR mapping already
  covered for `pand`/`verblijfsobject`.
- Standalone `phpunit-unit.xml` bootstrap has no live OpenRegister classes
  (`autoload-dev` maps `OCA\OpenRegister\` → `tests/Stubs/`); this change adds
  `tests/Stubs/Event/ObjectCreatingEventStub.php` / `ObjectUpdatingEventStub.php` (same
  `class_exists()`-guarded, manually-registered-in-`tests/bootstrap.php` convention as the existing
  `ObjectCreatedEventStub.php`) and extends `tests/Stubs/Db/ObjectEntity.php` with
  `setObject()`/`getObject()`/`jsonSerialize()` (additive — the existing `getUuid()`/`setUuid()`
  surface other stub consumers rely on is untouched).
