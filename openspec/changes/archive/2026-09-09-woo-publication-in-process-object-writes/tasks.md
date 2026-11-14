# Tasks: woo-publication-in-process-object-writes

## 1. OpenRegister service resolution

- [x] T01 — Add `SettingsService::getFileService()`, resolving
  `OCA\OpenRegister\Service\FileService` from the DI container with the same
  availability guard and null-on-failure contract as the existing
  `getObjectService()` / `getApprovalService()`.
  → `lib/Service/SettingsService.php`

## 2. OpenCatalogiApiClient — transport migration

- [x] T02 — Inject `SettingsService` into `OpenCatalogiApiClient` and add a
  private resolver that throws `RuntimeException('opencatalogi_api_error')`
  when OpenRegister is unavailable, so the client's existing error contract is
  unchanged. (REQ-WPI-003)
- [x] T03 — `createPublication()` and `attachDocument()` call
  `saveObject(object:, register:, schema:, uuid: null)`. (REQ-WPI-001)
- [x] T04 — `updatePublication()` performs a read-merge-write:
  `findSilent(id:, register:, schema:)` → `array_merge($stored, $payload)` →
  `saveObject(..., uuid: $id)`. Do NOT use `updateObject()` — its
  implementation is a full replace despite its docblock. (REQ-WPI-002)
- [x] T05 — `attachFile()` calls `FileService::addFile(objectEntity:,
  fileName:, content:, share:, tags:)`; keep `$mimeType` on the signature and
  document that OpenRegister's file API has never accepted one. (REQ-WPI-003)
- [x] T06 — Delete the OpenRegister objects-API path constants and the
  POST/PATCH transport branches; keep `resolveCatalog()`'s GET on
  `IClientService` with its service-account auth and swallow-and-continue
  contract. (REQ-WPI-001, REQ-WPI-004)

## 3. Tests

- [x] T07 — Rewrite `tests/Unit/Service/WooPublication/OpenCatalogiApiClientTest.php`
  against the new transport: assert the named arguments each operation passes,
  that no HTTP client is ever constructed for an object write, and that the
  `opencatalogi_api_error` contract still holds for a thrown service, a null
  service and a null file service.
- [x] T08 — Cover the read-merge-write directly: a stored object with five
  properties, a single-key partial payload, and an assertion that all five
  survive the save. This is the test that fails against a bare `saveObject()`.
- [x] T09 — Positive-control every new assertion: show the suite RED against a
  deliberately wrong route (a bare save instead of the merge, and
  `updateObject()` instead of `saveObject(uuid:)`), then revert and show it
  GREEN, with `git status` clean before either result is quoted.

## 4. Verification

- [x] T10 — hydra gate-62 (`check_store_and_settings_surface.py --gate store`)
  goes FAIL 1 → PASS on the app tree, measured on the same gate package sha on
  both sides.
- [x] T11 — `composer check:strict` scope (phpcs/phpmd/psalm/phpstan) stays at
  or below its pre-change finding count for the touched files.
