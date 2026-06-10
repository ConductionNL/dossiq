# Tasks: archief-edepot-handover-01-schema-config

> **Build status (hydra audit).** Greenfield. No archief schemas, services, or UI exist on dev. The 8-member archief-edepot-handover chain implements GiHandover/MDTO compliance from scratch (BewaarTermijnRegel, OverdrachtTrigger, SipBundel, OverdrachtTransactie, ArchiefBewijs, OverdrachtAuditLog schemas + daemon + sip-bundle generator + e-depot submission adapter + audit/admin UI). Tasks remain [ ] as genuine forward work for the next builder. See chain plan in design.md.

Chain member 1 of 8 (`kind: config`). Declares the `procest-archief` schemas + seed + integration test. Traces to giant Tasks 1–2.

## 1. Schema declaration

- [ ] Author `BewaarTermijnRegel` schema: zaaktypeKey, bewaartermijnJaren, selectielijstCategorie, selectielijstVersie, eDepotBestemming, eDepotConnectionId, mdtoVersion, uitzonderingen, isActive
- [ ] Author `OverdrachtTrigger` schema: zaakId, zaaktypeKey, afsluitingsDatum, bewaartermijnJaren, overdrachtDatum, status (enum), aanmeldingsDatum, redenBlokkering; relation → case
- [ ] Author `SipBundel` schema: zaakId, metadataXml, metadataXsdVersion, metadataXsdValid, documents[], bundleFormat, manifestChecksum, bundleSize, status, createdAt, bundleContent; relation → case
- [ ] Author `OverdrachtTransactie` schema: sipBundelId, eDepotConnectionId, eDepotNaam, submissionChannel, submissionTime, attemptNumber, httpStatus, responseBody, archivId, status, errorCode, errorDetail, nextRetryTime; relation → SipBundel
- [ ] Author `ArchiefBewijs` schema: zaakId, archivId, eDepotNaam, ingestionDatum, ontvangstBevestiging, checksums, sipBundelId, status, createdAt; relations → case, → SipBundel
- [ ] Author `OverdrachtAuditLog` schema: triggerId, zaakId, eventType (enum), timestamp, actor, details, relatedId; relations → OverdrachtTrigger, → case
- [ ] Validate all six schemas against the OpenRegister JSON Schema specification

## 2. Register + import wiring

- [ ] Declare the `procest-archief` register template referencing the six schemas
- [ ] Wire register/schema import via the repair-step pattern (idempotent on install)
- [ ] Verify generic REST endpoints exist per schema and return an empty collection pre-seed

## 3. Seed VNG default retention rules

- [ ] Define seed data for the three VNG default `BewaarTermijnRegel` rows (omgevingsvergunning 5yr, wmo-aanvraag 10yr, subsidie-verlening permanent) with selectielijst references and mdtoVersion "1.1"
- [ ] Implement the seed step so it runs on first install
- [ ] Make the seed idempotent (re-run does not duplicate)

## 4. Integration test

- [ ] Integration test: import on fresh instance asserts all six schemas + documented relations exist
- [ ] Integration test: each schema's generic endpoint returns an empty collection pre-seed
- [ ] Integration test: seed produces exactly the three documented rules and is idempotent on re-run
