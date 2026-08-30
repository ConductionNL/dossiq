# Tasks: brk-woz-register-adapters

## 1. BRK adapter seam

- [x] 1.1 `lib/Service/External/Brk/BrkAdapterInterface.php` — port, mirrors
      `BagAdapterInterface`.
- [x] 1.2 `lib/Service/External/Brk/BrkLookupResult.php` — result value object.
- [x] 1.3 `lib/Service/External/Brk/BrkResponseMapper.php` — pure normalization (kadastrale
      gemeente, sectie, perceelnummer, appartementsrechtVolgnummer, oppervlakte,
      soortCultuurBebouwd, zakelijkGerechtigden REFERENCES only, geo centroid).
- [x] 1.4 `lib/Service/External/Brk/BrkApiAdapter.php` — live adapter (Kadaster Haal Centraal BRK
      Bevragen API v2, `test`/`live` tiers, kadastrale-aanduiding validation, 404→NOT_FOUND, other
      failures→LOOKUP_ERROR, never throws).
- [x] 1.5 `lib/Service/External/Brk/LogBrkAdapter.php` — dormant default.
- [x] 1.6 Wire `BrkAdapterInterface` DI binding in `lib/AppInfo/Application.php` (mirrors the
      existing BAG/KvK/BRP `registerService` factory closures).

## 2. WOZ adapter seam

- [x] 2.1 `lib/Service/External/Woz/WozAdapterInterface.php` — port with three lookups (address,
      nummeraanduiding, wozobjectnummer).
- [x] 2.2 `lib/Service/External/Woz/WozLookupResult.php` — result value object.
- [x] 2.3 `lib/Service/External/Woz/WozResponseMapper.php` — pure normalization, selecting the
      MOST RECENT valuation from `vastgesteldeWaarden[]` (wozobjectnummer, waarde,
      waardepeildatum, grondoppervlakte, gebruiksdoel, nummeraanduidingId).
- [x] 2.4 `lib/Service/External/Woz/WozApiAdapter.php` — live adapter (Kadaster Haal Centraal WOZ
      Bevragen API, `test`/`live` tiers, Dutch postcode validation, 404→NOT_FOUND, other
      failures→LOOKUP_ERROR, never throws). Deliberately NOT bound to wozwaardeloket.nl (no
      programmatic API — see design.md Decision 2).
- [x] 2.5 `lib/Service/External/Woz/LogWozAdapter.php` — dormant default.
- [x] 2.6 Wire `WozAdapterInterface` DI binding in `lib/AppInfo/Application.php`.

## 3. HTTP surface

- [x] 3.1 `lib/Controller/BrkController.php` — `parcel`, `object` actions, `BagController`-style
      auth posture + error mapping.
- [x] 3.2 `lib/Controller/WozController.php` — `value` (nummeraanduidingId-or-address routing),
      `object` actions, same auth posture.
- [x] 3.3 Register routes in `appinfo/routes.php` (`brk#parcel`, `brk#object`, `woz#value`,
      `woz#object`).
- [x] 3.4 `src/services/brkApi.js` / `src/services/wozApi.js` — frontend fetch shims (no UI
      consumer yet — mirrors BAG/BRP/KvK precedent).

## 4. Tests

- [x] 4.1 `tests/Unit/Service/External/Brk/BrkAdapterTest.php` — request building, kadastrale-
      aanduiding validation matrix, dormant fallback, tier resolution, error mapping (404 vs 5xx).
- [x] 4.2 `tests/Unit/Service/External/Brk/BrkResponseMapperTest.php` — normalization matrix
      (full/partial/missing fields, multi-result, zakelijkGerechtigden privacy-scoping
      regression).
- [x] 4.3 `tests/Unit/Service/External/Brk/BrkContractTest.php` — offline contract lane against
      recorded Kadaster BRK Bevragen fixtures.
- [x] 4.4 `tests/Unit/Controller/BrkControllerTest.php` — 400/401/200-graceful-degraded coverage.
- [x] 4.5 `tests/Unit/Service/External/Woz/WozAdapterTest.php` — request building, postcode
      validation matrix, dormant fallback, tier resolution, error mapping (404 vs 5xx),
      nummeraanduiding-lookup query building.
- [x] 4.6 `tests/Unit/Service/External/Woz/WozResponseMapperTest.php` — normalization matrix
      including most-recent-valuation selection.
- [x] 4.7 `tests/Unit/Service/External/Woz/WozContractTest.php` — offline contract lane against
      recorded Kadaster WOZ Bevragen fixtures.
- [x] 4.8 `tests/Unit/Controller/WozControllerTest.php` — 400/401/200-graceful-degraded coverage,
      including nummeraanduidingId-vs-address routing.
- [x] 4.9 Full PHPUnit suite green (CI-equivalent `php:8.3-cli` container, `phpunit-unit.xml`).
- [x] 4.10 vitest green (`tests/vitest/brkApi.spec.js`, `tests/vitest/wozApi.spec.js`, including
      the wozwaardeloket.nl non-target regression assertion), `npm run build` exit 0, `test:l10n`
      parity green (no new UI strings).
- [x] 4.11 phpcs / phpstan / psalm / phpmd clean on the diff.

## 5. Follow-up (explicitly out of scope here)

- [ ] 5.1 Wire case/location fields to `BrkAdapterInterface`/`WozAdapterInterface` lookups in a
      VTH/tax case UI panel — no current save-path or detail-page consumer exists to hook into
      (mirrors BAG's Decision 3 follow-up). File as a separate change.
- [ ] 5.2 If Procest ever pursues municipal WOZ-data-holder onboarding (OIN + PKIOverheid
      certificate), document the operational registration steps outside this code change.

## 6. Spec + governance

- [x] 6.1 `openspec/changes/brk-woz-register-adapters/specs/brk-woz-register-adapters/spec.md`
      with `#### Scenario:` GIVEN/WHEN/THEN per requirement.
- [x] 6.2 `openspec validate brk-woz-register-adapters --type change --strict` passes.
- [x] 6.3 Archive to `openspec/changes/archive/2026-07-14-brk-woz-register-adapters/`.
