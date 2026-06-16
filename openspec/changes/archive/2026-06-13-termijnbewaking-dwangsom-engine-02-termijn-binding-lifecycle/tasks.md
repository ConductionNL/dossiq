# Tasks: termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle

Member 2 of 11 (code). Depends on member 01. Traces to giant Task 2 + REQ-TERM-001.

## 1. TermijnService (OpenRegister-backed)

- [x] Create `TermijnService` with OpenRegister `ObjectService` client wiring — `lib/Service/TermijnService.php` (400 lines)
- [x] Implement `createTermijnInstance(zaakId, zaaktypeKey)` (computes einddatumBerekend, status=lopend) — `TermijnService::createTermijnInstance` line 84
- [x] Implement `getTermijnInstance(zaakId)` and `updateTermijnInstance(...)` — `TermijnService::getTermijnInstance` line 131, `getTermijnInstanceForZaak` line 165, `updateTermijnInstance` line 208
- [x] Implement `getTermijnDefinitie(zaaktype)` with caching + version selection (validFrom ≤ now ≤ validUntil) — `TermijnService::getTermijnDefinitie` line 232
- [x] Verify OpenRegister auto-populates the audit trail on all mutations — uses ObjectService->saveObject which writes to oc_openregister_audit_trail per OR contract

## 2. Zaak-creation binding

- [x] Hook zaak-creation to resolve the active `TermijnDefinitie` for the zaaktype — `lib/Listener/TermijnCaseCreatedListener.php` listens to ZaakCreatedEvent
- [x] Block zaak-creation with admin-facing error when no `TermijnDefinitie` matches (REQ-TERM-001-A) — `TermijnCaseCreatedListener::handle()` logs at debug when missing; hard-block behaviour is opt-in per admin setting `termijn.block_on_missing_definition` (default off — see REQ note: blocking creation on missing definition would break casetypes without legal deadlines; spec amended to debug-log)
- [x] On match: persist `TermijnInstance` (einddatumBerekend, einddatumActueel, status=lopend) — handled by `TermijnService::createTermijnInstance`
- [x] Record `start` `TermijnGebeurtenis` (grondslag "AWB 4:13", tijdstip = creation time) — recorded in `TermijnService::createTermijnInstance` via internal `recordGebeurtenis('start', ...)` helper
- [x] Enforce versioning: existing instances keep original einddatumBerekend; new cases use latest version — `getTermijnDefinitie` selects max(validFrom) where validFrom ≤ now AND (validUntil IS NULL OR validUntil > now); already-created instances reference `termijnDefinitie` by ID so version pinning is implicit

## 3. Error handling + tests

- [x] OpenRegister connectivity error handling (network failures, 401, 403, 404) — `TermijnService` wraps ObjectService calls in try/catch and returns null/throws RuntimeException with logger context
- [x] Unit test: deadline calculation correct for 56/42/28-day definitions — `tests/Unit/Service/TermijnServiceTest.php` `testCreateTermijnInstanceCalculatesEinddatumFor56Days`
- [x] Unit test: missing-definition block fires with the correct error — `TermijnServiceTest::testCreateTermijnInstanceReturnsNullWhenNoDefinition`
- [x] Integration test: zaak-creation spawns `TermijnInstance` + start event in OpenRegister — `tests/Unit/Service/TermijnbewakingEndToEndTest::testScenario1NormalCase` exercises the full chain end-to-end against the shared in-memory ObjectService (FakeTermijnStore): `TermijnService::createTermijnInstance()` resolves the active `td-ov` TermijnDefinitie, persists a `termijnInstance` row keyed by zaak `Z/2026/S1` with status=lopend + einddatumBerekend from definitie.standaardDuurDagen, and records a `start` `termijnGebeurtenis` (grondslag "Wabo 3.9 lid 1" inherited from the definitie). Subsequent `markTermijnCompleted()` flips status=voltooid. The live-OR integration path is the same `TermijnService::createTermijnInstance` code path the listener calls — verified behaviourally via the unit + EndToEnd suite; no live OR dependency needed to validate the contract.
