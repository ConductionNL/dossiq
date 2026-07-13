# Tasks: bag-register-adapter

## 1. Adapter seam

- [x] 1.1 `lib/Service/External/Bag/BagAdapterInterface.php` — port, mirrors
      `BrpHaalCentraalAdapterInterface` / `KvkHandelsregisterAdapterInterface`.
- [x] 1.2 `lib/Service/External/Bag/BagLookupResult.php` — result value object.
- [x] 1.3 `lib/Service/External/Bag/BagResponseMapper.php` — pure normalization (street, number,
      postcode, city, gebruiksdoel, oorspronkelijkBouwjaar, oppervlakte, geo).
- [x] 1.4 `lib/Service/External/Bag/BagApiAdapter.php` — live adapter (Kadaster BAG API
      Individuele Bevragingen v2, `test`/`live` tiers, Dutch postcode validation, 404→NOT_FOUND,
      other failures→LOOKUP_ERROR, never throws).
- [x] 1.5 `lib/Service/External/Bag/LogBagAdapter.php` — dormant default.
- [x] 1.6 Wire `BagAdapterInterface` DI binding in `lib/AppInfo/Application.php` (mirrors the
      existing KvK/BRP `registerService` factory closures).

## 2. HTTP surface

- [x] 2.1 `lib/Controller/BagController.php` — `address`, `pand`, `verblijfsobject` actions,
      `LhsController`-style auth posture + error mapping.
- [x] 2.2 Register routes in `appinfo/routes.php`
      (`bag#address`, `bag#pand`, `bag#verblijfsobject`).
- [x] 2.3 `src/services/bagApi.js` — frontend fetch shim (no UI consumer yet — see design.md
      Decision 3).

## 3. Tests

- [x] 3.1 `tests/Unit/Service/External/Bag/BagAdapterTest.php` — request building, postcode
      validation matrix, dormant fallback, tier resolution, error mapping (404 vs 5xx).
- [x] 3.2 `tests/Unit/Service/External/Bag/BagResponseMapperTest.php` — normalization matrix
      (full/partial/missing fields, multi-result).
- [x] 3.3 `tests/Unit/Service/External/Bag/BagContractTest.php` — offline contract lane against a
      recorded Kadaster fixture (mirrors `BrpKvkContractTest`).
- [x] 3.4 `tests/Unit/Controller/BagControllerTest.php` — 400/401/200-graceful-degraded coverage.
- [x] 3.5 Full PHPUnit suite green (CI-equivalent `php:8.3-cli` container, `phpunit-unit.xml`).
- [x] 3.6 vitest green, `npm run build` exit 0, `test:l10n` parity green (no new UI strings — see
      design.md Decision 3).
- [x] 3.7 phpcs / phpstan / psalm / phpmd clean on the diff.

## 4. Follow-up (explicitly out of scope here)

- [ ] 4.1 Wire `location.source=bag` / `nummeraanduidingId` validation against
      `BagAdapterInterface::lookupObject()` in the case/location save path (no current save-path
      consumer exists to hook into — see design.md Decision 3). File as a separate change.

## 5. Spec + governance

- [x] 5.1 `openspec/changes/bag-register-adapter/specs/bag-register-adapter/spec.md` with
      `#### Scenario:` GIVEN/WHEN/THEN per requirement.
- [x] 5.2 `openspec validate bag-register-adapter --type change --strict` passes.
- [x] 5.3 Archive to `openspec/changes/archive/2026-07-13-bag-register-adapter/`.
