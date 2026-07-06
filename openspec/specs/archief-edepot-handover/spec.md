---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development. 2026-06-13 member 06 completed the genuinely-absent rollback/retry capability — RollbackManager (onIngestionFailure + retryAfterCorrection) and POST /api/archief/triggers/{triggerId}/retry (archief-role + per-trigger IDOR/state guards), reusing ArchivalTriggerService + ProofOfTransferService; PHPUnit + Newman cover it.
---
# archief-edepot-handover Specification

## Purpose
Procest's archival/e-Depot handover is executed by OpenRegister's retention, destruction, and e-Depot transfer abstractions (ADR-022). Procest contributes zaakgericht domain knowledge declaratively (retention terms per zaaktype via `x-openregister-archival`, selectielijst mapping, TMLO/MDTO field mapping via `tmloDefaults`) and translates Awb lifecycle events into OpenRegister legal holds. Procest runs no archival pipeline of its own.

@e2e exclude Retention, destruction, SIP building, e-Depot transfer, durable retry and proof-of-transfer are executed by OpenRegister (ADR-022) and covered by OR's own e2e/Newman/unit suites; the procest-side surface is declarative schema config plus a backend legal-hold listener and a fail-closed migration repair step (guarded, unit-covered). There is no procest-only browser flow that drives these scenarios without OpenRegister's archival stack installed — mirrors the sla-charts-via-analytics-leaf / stuf-zkn-outbound precedent.
## Requirements
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

