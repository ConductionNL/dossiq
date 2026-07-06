---
status: proposed
---

# Spec: archief-edepot-handover (delta — pipeline moves to OpenRegister)

**Status:** proposed
**Scope:** procest (declarative config + domain listeners only; engine is OpenRegister's per ADR-022)
**Depends on:** OpenRegister archival stack on `origin/development` — RetentionService, Archival/* (RetentionEvaluator, LegalHoldService, DestructionService), DestructionCheckJob/DestructionExecutionJob, TmloService, Edepot/* (EdepotTransferService, SipPackageBuilder, MdtoXmlGenerator, Transport/TransportInterface); OR-side deltas OR-AD-1..3 where noted
**Standard:** Archiefwet 1995, MDTO 1.0+, OAIS (ISO 14721), BagIt (RFC 8493, via OR-AD-1), VNG selectielijst

## Purpose

Procest's archival/e-Depot handover is executed by OpenRegister's retention, destruction, and
e-Depot transfer abstractions. Procest contributes zaakgericht domain knowledge declaratively
(retention terms per zaaktype, selectielijst mapping, TMLO/MDTO field mapping) and translates Awb
lifecycle events into OR legal holds. Procest runs no archival pipeline of its own.

## ADDED Requirements

### Requirement: Retention rules MUST be declared, not executed, by procest

Procest SHALL express per-zaaktype retention (bewaartermijn, selectielijst categorie/versie,
e-Depot bestemming, MDTO version) as `x-openregister-archival` configuration on its schemas,
migrated from the current `bewaarTermijnRegel` data (including municipality edits, not only the
VNG seed defaults). Procest SHALL NOT run its own trigger daemon (`ArchivalTriggerService`),
batch service, or retention matching; OR's RetentionService/RetentionEvaluator and destruction
jobs own execution.

#### Scenario: Closed case is nominated by OpenRegister

- **GIVEN** the case schema carries retention config for zaaktype "omgevingsvergunning" (5 years)
- **WHEN** a case of that type is closed and OR's retention evaluation runs
- **THEN** OpenRegister MUST compute the archiefactiedatum and nominate the case in its archival workflow
- **AND** no procest service MUST have executed rule matching

#### Scenario: Unconfigured zaaktype surfaces in OR, not in a procest blocklist

- **WHEN** a case whose zaaktype has no retention config is closed
- **THEN** the gap MUST surface in OpenRegister's archivist view as unconfigured
- **AND** procest MUST NOT maintain a parallel `geblokkeerd-geen-regel` administration

### Requirement: Legal proceedings MUST suspend archival via OR legal holds

When a bezwaar or beroep is registered against a case, procest SHALL place a legal hold on the
case via OpenRegister's `LegalHoldService`; when the proceeding reaches its final Awb outcome,
procest SHALL release the hold. Suspension enforcement (retention evaluator and destruction jobs
skipping held objects) is OpenRegister's.

#### Scenario: Bezwaar places a hold

- **GIVEN** a closed case nominated for handover
- **WHEN** a bezwaar is registered against it
- **THEN** procest MUST place an OR legal hold on the case
- **AND** OR's transfer and destruction processing MUST skip the case while the hold stands

#### Scenario: Final outcome releases the hold

- **WHEN** the bezwaar/beroep chain reaches a final outcome
- **THEN** procest MUST release the hold
- **AND** the case MUST re-enter OR's archival evaluation without manual re-nomination

### Requirement: SIP building and submission MUST run through the OpenRegister e-Depot pipeline

Procest SHALL delegate MDTO/TMLO metadata generation, SIP packaging, transfer batching, in-flow
retry, status tracking, and audit logging to OpenRegister (`TmloService`,
`Edepot/EdepotTransferService`, `SipPackageBuilder`, `MdtoXmlGenerator`). Procest SHALL contribute its zaak→TMLO/MDTO field
mapping as schema/register configuration. The submission boundary SHALL remain pluggable at OR's
`Edepot/Transport/TransportInterface` (mock/log transport by default); binding a real e-Depot test
endpoint is owned by the `external-integrations-test-environments` change. Procest's
`BagItBundlerService`, `ArchivalSubmissionRetryService`, `EDepotSubmissionAdapterInterface`, and
`TmloMetadataBuilderAdapterInterface` seams SHALL be retired; BagIt output, durable retry, and
proof-of-transfer records are OR-side deltas (OR-AD-1..3).

#### Scenario: Handover produces an OR-built SIP

- **GIVEN** a case eligible for overbrenging with procest's TMLO mapping declared
- **WHEN** OR's e-Depot transfer runs
- **THEN** the SIP (MDTO metadata + documents) MUST be built by OpenRegister
- **AND** submission MUST go through the configured OR transport
- **AND** no procest service MUST have bundled or submitted anything

#### Scenario: Transfer state is visible in OR only

- **WHEN** an archivist inspects a running or failed transfer
- **THEN** status, attempts, and audit trail MUST be readable from OpenRegister's surface
- **AND** procest MUST NOT persist `overdrachtTrigger`/`overdrachtTransactie`/`overdrachtAuditLog` records

## REMOVED Requirements

**Reason (all):** the entire app-local archival/e-Depot capability is superseded by OpenRegister's
archival stack per ADR-022 (no exception ADR exists). The eight services, six `External/Tmlo`
adapter files, `ArchiefController`, two console commands, three background jobs, six
`62-archief-edepot.json` schemas, and the four Archief Vue views are removed after the migration
repair step preserves in-flight data (retention config declared on the case schema, suspended cases
re-nominated as legal holds, proof-of-transfer exported to the zaakdossier).

### Requirement: procest-archief register declares six archival schemas

**Reason:** the six schemas (`bewaarTermijnRegel`, `overdrachtTrigger`, `sipBundel`,
`overdrachtTransactie`, `archiefBewijs`, `overdrachtAuditLog`) are retired; retention is declared on
the case schema and pipeline state lives in OpenRegister's transfer/proof records.

### Requirement: VNG default retention rules are seeded idempotently

**Reason:** VNG defaults are now declarative `x-openregister-archival` config on the case schema; the
`ArchiefEdepotSeedDataService` + `SeedArchiefEdepotData` repair step are removed.

### Requirement: Nightly detection assigns retention and marks ready cases

**Reason:** `ArchivalTriggerService` + `ArchivalTriggerScanJob` are removed; OpenRegister's
`RetentionEvaluator` + `DestructionCheckJob` own detection and archiefactiedatum.

### Requirement: Cases without a retention rule are blocked and DIV is notified

**Reason:** unconfigured zaaktypen surface in OpenRegister's archivist view; procest keeps no
`geblokkeerd-geen-regel` administration.

### Requirement: Active bezwaar/beroep suspends the trigger

**Reason:** replaced by an OpenRegister legal hold placed/released by `BezwaarLegalHoldListener`
(see ADDED "Legal proceedings MUST suspend archival via OR legal holds").

### Requirement: Bundler produces XSD-valid MDTO/TMLO metadata

**Reason:** MDTO/TMLO metadata is generated by OpenRegister's `TmloService` + `MdtoXmlGenerator`
from declared schema config; the `TmloMetadataBuilderAdapter` seam and `MetadataBundlerService` are
removed.

### Requirement: Missing document-type blocks bundling

**Reason:** SIP bundling is OpenRegister's `SipPackageBuilder`; procest runs no bundler.

### Requirement: Each document is exported as PDF/A plus preserved original with checksums

**Reason:** SIP packaging (document export + checksums) is OpenRegister's `SipPackageBuilder`.

### Requirement: Conversion failure blocks bundling with an actionable task

**Reason:** bundling and its failure handling belong to OpenRegister's e-Depot pipeline.

### Requirement: Batch document conversion respects a configurable rate limit

**Reason:** batching/rate limiting is OpenRegister's `EdepotTransferService`/`TransferListService`.

### Requirement: SIP is packaged as BagIt with a SHA-256 manifest

**Reason:** BagIt output is an OpenRegister `SipPackageBuilder` capability (OR-AD-1);
`BagItBundlerService` is removed.

### Requirement: SIP is submitted to the configured e-Depot channel with authentication

**Reason:** submission is OpenRegister's pluggable `Edepot/Transport/TransportInterface`;
`EDepotSubmissionAdapter` and `ArchivalBatchService` are removed.

### Requirement: Failed submissions retry with exponential backoff and escalate after five attempts

**Reason:** durable retry + escalation is an OpenRegister capability (OR-AD-2);
`ArchivalSubmissionRetryService` + `ArchivalSubmissionRetryJob` + `RollbackManager` are removed.

### Requirement: DIV can run concurrent batch transfers with per-case reporting

**Reason:** batch transfer + per-case status is OpenRegister's transfer surface (`/api/transfers`).

### Requirement: Annual inspection export produces an audit-grade ZIP

**Reason:** the archival inspection export (`ArchiefController#inspectionExport`) is removed; audit
data lives in OpenRegister's transfer/audit records.

### Requirement: Archival events are queryable from an append-only audit log

**Reason:** the `overdrachtAuditLog` schema is retired; OpenRegister's native audit trail records
transfer/hold/destruction events.

### Requirement: DIV admin can manage retention rules through a validated UI

**Reason:** the Archief retention-rules admin UI is removed; retention is declarative config
maintained on the case schema (see the admin doc).

### Requirement: DIV can monitor archival status on a dashboard

**Reason:** the Archief dashboard Vue views are removed; archivist monitoring is OpenRegister's.

### Requirement: The capability is covered by tests and operator documentation

**Reason:** the app-local capability is retired; the operator doc is rewritten for the OR-owned
pipeline and the app-local tests are removed.

### Requirement: Successful transfer captures proof of transfer

**Reason:** proof-of-transfer is an OpenRegister durable record (OR-AD-3); the `archiefBewijs` schema
and `ProofOfTransferService` are removed (existing proofs exported to the zaakdossier by the repair
step).

### Requirement: Ingestion rejection rolls back without losing the dossier

**Reason:** transfer rollback/terminal-failure handling is OpenRegister's; `RollbackManager` is
removed.

### Requirement: DIV can retry archival after correcting the case

**Reason:** retry is OpenRegister's durable-retry capability; the `ArchiefController#retry` endpoint
is removed.
