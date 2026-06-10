# Tasks: termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle

Member 2 of 11 (code). Depends on member 01. Traces to giant Task 2 + REQ-TERM-001.

## 1. TermijnService (OpenRegister-backed)

- [~] Create `TermijnService` with OpenRegister `ObjectService` client wiring — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `createTermijnInstance(zaakId, zaaktypeKey)` (computes einddatumBerekend, status=lopend) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `getTermijnInstance(zaakId)` and `updateTermijnInstance(...)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `getTermijnDefinitie(zaaktype)` with caching + version selection (validFrom ≤ now ≤ validUntil) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Verify OpenRegister auto-populates the audit trail on all mutations — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Zaak-creation binding

- [~] Hook zaak-creation to resolve the active `TermijnDefinitie` for the zaaktype — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Block zaak-creation with admin-facing error when no `TermijnDefinitie` matches (REQ-TERM-001-A) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] On match: persist `TermijnInstance` (einddatumBerekend, einddatumActueel, status=lopend) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Record `start` `TermijnGebeurtenis` (grondslag "AWB 4:13", tijdstip = creation time) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Enforce versioning: existing instances keep original einddatumBerekend; new cases use latest version — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Error handling + tests

- [~] OpenRegister connectivity error handling (network failures, 401, 403, 404) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit test: deadline calculation correct for 56/42/28-day definitions — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit test: missing-definition block fires with the correct error — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: zaak-creation spawns `TermijnInstance` + start event in OpenRegister — deferred to downstream cycle / fleet-wide adoption (handoff)
