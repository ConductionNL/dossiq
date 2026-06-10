# Tasks: termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle

> **Build status (hydra audit).** Greenfield. No TermijnDefinitie/TermijnInstance/TermijnGebeurtenis/Ingebrekestelling/Dwangsom schemas, no termijn-binding lifecycle, no daily-scan escalation daemon, no dwangsom calculation/financial integration, no burger notifications, no reporting/REST-API surfaces on dev. The 11-member chain delivers the AWB termijnbewaking + dwangsom engine from scratch. Tasks stay [ ] as genuine forward work.

Member 2 of 11 (code). Depends on member 01. Traces to giant Task 2 + REQ-TERM-001.

## 1. TermijnService (OpenRegister-backed)

- [ ] Create `TermijnService` with OpenRegister `ObjectService` client wiring
- [ ] Implement `createTermijnInstance(zaakId, zaaktypeKey)` (computes einddatumBerekend, status=lopend)
- [ ] Implement `getTermijnInstance(zaakId)` and `updateTermijnInstance(...)`
- [ ] Implement `getTermijnDefinitie(zaaktype)` with caching + version selection (validFrom ≤ now ≤ validUntil)
- [ ] Verify OpenRegister auto-populates the audit trail on all mutations

## 2. Zaak-creation binding

- [ ] Hook zaak-creation to resolve the active `TermijnDefinitie` for the zaaktype
- [ ] Block zaak-creation with admin-facing error when no `TermijnDefinitie` matches (REQ-TERM-001-A)
- [ ] On match: persist `TermijnInstance` (einddatumBerekend, einddatumActueel, status=lopend)
- [ ] Record `start` `TermijnGebeurtenis` (grondslag "AWB 4:13", tijdstip = creation time)
- [ ] Enforce versioning: existing instances keep original einddatumBerekend; new cases use latest version

## 3. Error handling + tests

- [ ] OpenRegister connectivity error handling (network failures, 401, 403, 404)
- [ ] Unit test: deadline calculation correct for 56/42/28-day definitions
- [ ] Unit test: missing-definition block fires with the correct error
- [ ] Integration test: zaak-creation spawns `TermijnInstance` + start event in OpenRegister
