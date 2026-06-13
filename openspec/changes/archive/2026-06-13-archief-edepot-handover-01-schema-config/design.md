# Design: archief-edepot-handover-01-schema-config

## Scope

This member declares the `procest-archief` data model and seed only. It is the `kind: config` head of the `archief-edepot-handover` chain. Service classes, daemons, controllers, and UI are explicitly out of scope here — they arrive in members 02–08.

## Declarative-first (ADR-031)

All six schemas are declared as OpenRegister schema JSON, not as PHP entities. Per ADR-031 the data model is declarative metadata; the only PHP in this member is the repair/seed wiring (plumbing, not business logic) and the integration test. No service computes or derives the schema shape at runtime.

## Data model — six schemas

### BewaarTermijnRegel (retention rule configuration)
Fields: `zaaktypeKey` (string, required), `bewaartermijnJaren` (integer or "permanent"), `selectielijstCategorie`, `selectielijstVersie`, `eDepotBestemming`, `eDepotConnectionId`, `mdtoVersion` (enum "1.2.1" | "1.1"), `uitzonderingen` (array), `isActive` (boolean). Lookup table — no heavy relations.

### OverdrachtTrigger (archival readiness detector)
Fields: `zaakId` (string, required), `zaaktypeKey`, `afsluitingsDatum` (date), `bewaartermijnJaren`, `overdrachtDatum` (date), `status` (enum: gepland, gereed-voor-overdracht, in-overdracht, geslaagd, gefaald, vernietigd, geblokkeerd-geen-regel, opgeschort-juridische-procedure), `aanmeldingsDatum` (datetime), `redenBlokkering` (string, optional). Relation → case.

### SipBundel (submission information package)
Fields: `zaakId` (required), `metadataXml` (text), `metadataXsdVersion`, `metadataXsdValid` (boolean), `documents` (array of {documentId, format, fileName, fileSize, checksumSha256}), `bundleFormat` (enum BagIt | ToPX-XML), `manifestChecksum`, `bundleSize` (integer), `status` (enum prepared, ready-for-submission, submitted, failed, archived), `createdAt`, `bundleContent`. Relation → case.

### OverdrachtTransactie (e-Depot submission transaction)
Fields: `sipBundelId` (required), `eDepotConnectionId`, `eDepotNaam`, `submissionChannel` (enum HTTPS_POST | SFTP_UPLOAD | S3_PUT), `submissionTime`, `attemptNumber` (integer), `httpStatus`, `responseBody`, `archivId`, `status` (enum pending, submitted, succeeded, failed, retrying), `errorCode`, `errorDetail`, `nextRetryTime`. Relation → SipBundel.

### ArchiefBewijs (proof of transfer)
Fields: `zaakId` (required), `archivId` (required), `eDepotNaam`, `ingestionDatum`, `ontvangstBevestiging`, `checksums` (object), `sipBundelId`, `status` (enum received, verified, retained), `createdAt`. Relations → case, → SipBundel.

### OverdrachtAuditLog (append-only archival event stream)
Fields: `triggerId` (required), `zaakId` (required), `eventType` (enum, see giant Task 18), `timestamp`, `actor`, `details` (object), `relatedId`. Relations → OverdrachtTrigger, → case.

## Declarative-vs-imperative boundary

| Concern | Where |
|---|---|
| Schema field shape, enums, relations | Declared here (schema JSON) |
| Register + schema import | Repair step here (plumbing) |
| Seed of VNG default rules | Seed step here (idempotent) |
| Any read/write of these schemas | Members 02–08 (consumers) |

## Seed

Three `BewaarTermijnRegel` rows seeded idempotently on first install (re-run does not duplicate):

| zaaktypeKey | bewaartermijnJaren | selectielijstCategorie | eDepotBestemming | mdtoVersion |
|---|---|---|---|---|
| omgevingsvergunning | 5 | Selectielijst gemeenten 4.1.3 | RHC Utrecht e-Depot | 1.1 |
| wmo-aanvraag | 10 | Selectielijst gemeenten 5.2.1 | Regionaal Archief Zuid-Holland | 1.1 |
| subsidie-verlening | permanent | Selectielijst gemeenten 3.4.2 | RHC Utrecht e-Depot | 1.1 |

DIV admin can override per-organisation (a later chain member exposes the UI; the data path exists from here).

## Security (ADR-005)

Schema CRUD inherits OpenRegister's RBAC. Register/schema creation is admin-only by OR convention; consuming members add per-endpoint guards. No new endpoints are exposed by this config member beyond OR's generic schema REST surface.

## Integration test

A single integration test that: (1) runs the register/schema import on a fresh instance; (2) asserts all six schemas exist with the documented required fields + relations; (3) asserts each schema's generic REST endpoint returns an empty collection pre-seed; (4) asserts the seed produced exactly the three documented rules; (5) re-runs the seed and asserts no duplication.

## Traceability

Giant Task 1 (schema registration) + Task 2 (seed retention rules).
