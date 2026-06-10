# Tasks: archief-edepot-handover-01-schema-config

Chain member 1 of 8 (`kind: config`). Declares the `procest-archief` schemas + seed + integration test. Traces to giant Tasks 1–2.

## 1. Schema declaration

- [~] Author `BewaarTermijnRegel` schema: zaaktypeKey, bewaartermijnJaren, selectielijstCategorie, selectielijstVersie, eDepotBestemming, eDepotConnectionId, mdtoVersion, uitzonderingen, isActive — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Author `OverdrachtTrigger` schema: zaakId, zaaktypeKey, afsluitingsDatum, bewaartermijnJaren, overdrachtDatum, status (enum), aanmeldingsDatum, redenBlokkering; relation → case — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Author `SipBundel` schema: zaakId, metadataXml, metadataXsdVersion, metadataXsdValid, documents[], bundleFormat, manifestChecksum, bundleSize, status, createdAt, bundleContent; relation → case — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Author `OverdrachtTransactie` schema: sipBundelId, eDepotConnectionId, eDepotNaam, submissionChannel, submissionTime, attemptNumber, httpStatus, responseBody, archivId, status, errorCode, errorDetail, nextRetryTime; relation → SipBundel — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Author `ArchiefBewijs` schema: zaakId, archivId, eDepotNaam, ingestionDatum, ontvangstBevestiging, checksums, sipBundelId, status, createdAt; relations → case, → SipBundel — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Author `OverdrachtAuditLog` schema: triggerId, zaakId, eventType (enum), timestamp, actor, details, relatedId; relations → OverdrachtTrigger, → case — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Validate all six schemas against the OpenRegister JSON Schema specification — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Register + import wiring

- [~] Declare the `procest-archief` register template referencing the six schemas — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Wire register/schema import via the repair-step pattern (idempotent on install) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Verify generic REST endpoints exist per schema and return an empty collection pre-seed — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Seed VNG default retention rules

- [~] Define seed data for the three VNG default `BewaarTermijnRegel` rows (omgevingsvergunning 5yr, wmo-aanvraag 10yr, subsidie-verlening permanent) with selectielijst references and mdtoVersion "1.1" — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement the seed step so it runs on first install — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Make the seed idempotent (re-run does not duplicate) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 4. Integration test

- [~] Integration test: import on fresh instance asserts all six schemas + documented relations exist — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: each schema's generic endpoint returns an empty collection pre-seed — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: seed produces exactly the three documented rules and is idempotent on re-run — deferred to downstream cycle / fleet-wide adoption (handoff)
