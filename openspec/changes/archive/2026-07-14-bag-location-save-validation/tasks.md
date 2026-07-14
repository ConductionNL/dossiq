# Tasks: bag-location-save-validation

## 1. BAG adapter — nummeraanduiding lookup support (REQ-BLV-004)

- [x] 1.1 `BagApiAdapter::OBJECT_PATHS` — add `'nummeraanduiding' => 'nummeraanduidingen'`.
- [x] 1.2 Update `BagApiAdapter::lookupObject()` + `BagAdapterInterface::lookupObject()` docblocks
      (`$objectType` now `pand` | `verblijfsobject` | `nummeraanduiding`).
- [x] 1.3 `BagAdapterTest` — add a `lookupObject('nummeraanduiding', ...)` case (404 → NOT_FOUND,
      2xx → FOUND against `/nummeraanduidingen/{id}`).

## 2. LocationBagValidationListener (REQ-BLV-001, REQ-BLV-002, REQ-BLV-003)

- [x] 2.1 `lib/Listener/LocationBagValidationListener.php` — schema gate via
      `SettingsService::getConfigValue('location_schema')`, `source=bag` ⇒ `nummeraanduidingId`
      required + 16-digit format, best-effort existence check via `BagAdapterInterface`.
- [x] 2.2 Register on `ObjectCreatingEvent` + `ObjectUpdatingEvent` in
      `lib/AppInfo/Application.php`.
- [x] 2.3 Translated error messages (`IL10N::t()`) for `nummeraanduidingId.required` / `.invalid` /
      `.unknown`; add both strings to `l10n/en.json` + `l10n/nl.json`.

## 3. Tests

- [x] 3.1 `tests/Stubs/Event/ObjectCreatingEventStub.php` + `ObjectUpdatingEventStub.php`
      (`class_exists()`-guarded, registered in `tests/bootstrap.php`).
- [x] 3.2 Extend `tests/Stubs/Db/ObjectEntity.php` with `setObject()`/`getObject()`/
      `jsonSerialize()`.
- [x] 3.3 `tests/Unit/Listener/LocationBagValidationListenerTest.php` — full validation matrix:
      absent/non-bag source (no-op), non-`location` schema (no-op), `source=bag` + missing id
      (reject), malformed id (reject), valid id + dormant adapter (accept, no adapter call),
      valid id + non-dormant `FOUND` (accept), valid id + non-dormant `NOT_FOUND` (reject), valid
      id + non-dormant `Throwable`/`LOOKUP_ERROR` (accept-with-warning).
- [x] 3.4 Full PHPUnit suite green (CI-equivalent `php:8.3-cli` container, `phpunit-unit.xml`).
- [x] 3.5 `npm run build` exit 0 (no frontend source changed, but the gate still runs clean).
- [x] 3.6 `l10n` en/nl parity check green (`tests/l10n/check-l10n.js`).

## 4. Follow-ups filed, not built here

- [ ] 4.1 `case-location/spec.md` (REQ-LOC-05/06) documents a `LocationService` deleted in commit
      `bac6f7073` — the spec is stale. File a spec-cleanup/retrofit change.
