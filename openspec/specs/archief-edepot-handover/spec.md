---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development. 2026-06-13 member 06 completed the genuinely-absent rollback/retry capability — RollbackManager (onIngestionFailure + retryAfterCorrection) and POST /api/archief/triggers/{triggerId}/retry (archief-role + per-trigger IDOR/state guards), reusing ArchivalTriggerService + ProofOfTransferService; PHPUnit + Newman cover it.
---
# archief-edepot-handover Specification

## Purpose
Dossiq's archival/e-Depot handover is executed by OpenRegister's retention, destruction, and e-Depot transfer abstractions (ADR-022). Dossiq contributes zaakgericht domain knowledge declaratively (retention terms per zaaktype via `x-openregister-archival`, selectielijst mapping, TMLO/MDTO field mapping via `tmloDefaults`) and translates Awb lifecycle events into OpenRegister legal holds. Dossiq runs no archival pipeline of its own.

@e2e exclude Retention, destruction, SIP building, e-Depot transfer, durable retry and proof-of-transfer are executed by OpenRegister (ADR-022) and covered by OR's own e2e/Newman/unit suites; the dossiq-side surface is declarative schema config plus a backend legal-hold listener and a fail-closed migration repair step (guarded, unit-covered). There is no dossiq-only browser flow that drives these scenarios without OpenRegister's archival stack installed — mirrors the sla-charts-via-analytics-leaf / stuf-zkn-outbound precedent.
## Requirements
### Requirement: Retention rules MUST be declared, not executed, by dossiq

Dossiq SHALL express per-zaaktype retention (bewaartermijn, selectielijst categorie/versie,
e-Depot bestemming, MDTO version) as `x-openregister-archival` configuration on its schemas,
migrated from the current `bewaarTermijnRegel` data (including municipality edits, not only the
VNG seed defaults). Dossiq SHALL NOT run its own trigger daemon (`ArchivalTriggerService`),
batch service, or retention matching; OR's RetentionService/RetentionEvaluator and destruction
jobs own execution.

#### Scenario: Closed case is nominated by OpenRegister

- **GIVEN** the case schema carries retention config for zaaktype "omgevingsvergunning" (5 years)
- **WHEN** a case of that type is closed and OR's retention evaluation runs
- **THEN** OpenRegister MUST compute the archiefactiedatum and nominate the case in its archival workflow
- **AND** no dossiq service MUST have executed rule matching

#### Scenario: Unconfigured zaaktype surfaces in OR, not in a dossiq blocklist

- **WHEN** a case whose zaaktype has no retention config is closed
- **THEN** the gap MUST surface in OpenRegister's archivist view as unconfigured
- **AND** dossiq MUST NOT maintain a parallel `geblokkeerd-geen-regel` administration

### Requirement: Legal proceedings MUST suspend archival via OR legal holds

When a bezwaar or beroep is registered against a case, dossiq SHALL place a legal hold on the
case via OpenRegister's `LegalHoldService`; when the proceeding reaches its final Awb outcome,
dossiq SHALL release the hold. Suspension enforcement (retention evaluator and destruction jobs
skipping held objects) is OpenRegister's.

#### Scenario: Bezwaar places a hold

- **GIVEN** a closed case nominated for handover
- **WHEN** a bezwaar is registered against it
- **THEN** dossiq MUST place an OR legal hold on the case
- **AND** OR's transfer and destruction processing MUST skip the case while the hold stands

#### Scenario: Final outcome releases the hold

- **WHEN** the bezwaar/beroep chain reaches a final outcome
- **THEN** dossiq MUST release the hold
- **AND** the case MUST re-enter OR's archival evaluation without manual re-nomination

### Requirement: SIP building and submission MUST run through the OpenRegister e-Depot pipeline

Dossiq SHALL delegate MDTO/TMLO metadata generation, SIP packaging, transfer batching, in-flow
retry, status tracking, and audit logging to OpenRegister (`TmloService`,
`Edepot/EdepotTransferService`, `SipPackageBuilder`, `MdtoXmlGenerator`). Dossiq SHALL contribute its zaak→TMLO/MDTO field
mapping as schema/register configuration. The submission boundary SHALL remain pluggable at OR's
`Edepot/Transport/TransportInterface` (mock/log transport by default); binding a real e-Depot test
endpoint is owned by the `external-integrations-test-environments` change. Dossiq's
`BagItBundlerService`, `ArchivalSubmissionRetryService`, `EDepotSubmissionAdapterInterface`, and
`TmloMetadataBuilderAdapterInterface` seams SHALL be retired; BagIt output, durable retry, and
proof-of-transfer records are OR-side deltas (OR-AD-1..3).

#### Scenario: Handover produces an OR-built SIP

- **GIVEN** a case eligible for overbrenging with dossiq's TMLO mapping declared
- **WHEN** OR's e-Depot transfer runs
- **THEN** the SIP (MDTO metadata + documents) MUST be built by OpenRegister
- **AND** submission MUST go through the configured OR transport
- **AND** no dossiq service MUST have bundled or submitted anything

#### Scenario: Transfer state is visible in OR only

- **WHEN** an archivist inspects a running or failed transfer
- **THEN** status, attempts, and audit trail MUST be readable from OpenRegister's surface
- **AND** dossiq MUST NOT persist `overdrachtTrigger`/`overdrachtTransactie`/`overdrachtAuditLog` records


### Requirement: One date decides when a case is destroyed, and it is the ZGW one

Three mechanisms currently compute a retention answer for the same case, and only
one of them is legally correct. This requirement names which, and records what the
other two are for.

**The authoritative answer is `case.archiveActionDate` and `case.archiveNomination`,**
derived from the case's RESULT TYPE by zrc-021
(`Service\Archival\ArchivalNominationDeriver`), from the brondatum the
`brondatumArchiefprocedure` names, which for an ordinary zaak is the einddatum.
That is what the Archiefwet requires, what a ZGW consumer reads off the zaak, and
the only one of the three that varies with the OUTCOME of the case. A granted
permit and a withdrawn one are not kept for the same term.

**`x-openregister-archival` on the case schema is NOT a second answer and SHALL NOT
be made into one.** It is retained for the delete gates it earns, and its
`retention.default` SHALL be the only key it carries. Dossiq SHALL NOT declare
per-case-type retention rules there. Two OpenRegister properties make it unable to
express a zaak's bewaartermijn, and neither is dossiq's to change:

- it counts from the row's `_created` timestamp, with no override. A duration from
  creation is a different number from the same duration after afhandeling.
- its hourly sweep (`ArchivalRetentionTask`) does not check legal holds. It passes
  `_retentionSweep: true`, which is the one flag that skips the immutability gate
  every other destruction path honours, and dossiq's bezwaar and beroep handling
  depends on that gate.

**`BeschikkingService::archive()` computes a third date** and it is app-local
arithmetic: a hardcoded `P15Y` at the call site, added to the mandate approval date,
for every beschikking of every case type. Its adapter's own docblock claims the
term is governed declaratively by `x-openregister-archival`; nothing reads that
annotation. The claim SHALL be corrected or the computation SHALL be moved onto the
authoritative date.

#### Scenario: The annotation carries no rule that contradicts the result type
@e2e exclude A schema annotation has no browser surface; asserted by tests/vitest/caseRetentionAnnotation.spec.js, which also refuses a rule naming a case type nothing seeds.

- **GIVEN** the case schema's `x-openregister-archival`
- **WHEN** it is read
- **THEN** `retention` SHALL carry `default` and no `rules`
- **AND** no schema other than `case` SHALL carry the annotation

#### Scenario: A rule naming an absent case type is refused
@e2e exclude Same absent surface; asserted by the same vitest guard.

- **GIVEN** a retention rule whose condition names a case type slug
- **WHEN** nothing in the seed declares a case type with that slug
- **THEN** the guard SHALL fail, because a rule that can never match is a
  destruction policy the app appears to have and does not

#### Scenario: Three shipped rules could never fire
@e2e exclude Historical record of the defect this requirement closes; the guard above is what keeps it closed.

- **GIVEN** the rules that shipped, naming `omgevingsvergunning-regulier`,
  `wmo-melding` and `subsidie-verlening`
- **WHEN** each is matched against the fourteen case types the app seeds
- **THEN** none of the three slugs SHALL exist
- **AND** every case SHALL therefore have fallen through to the flat ten-year
  default, including the cases the rules were written to give five and twenty
- **AND** the `reason` strings SHALL NOT have claimed VNG selectielijst compliance
  the app does not have
