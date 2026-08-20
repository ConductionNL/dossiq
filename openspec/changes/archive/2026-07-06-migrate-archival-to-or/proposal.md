# Proposal: migrate-archival-to-or

kind: abstraction consolidation / retirement — cites **ADR-022** (apps-consume-or-abstractions).
Product-owner decision 2026-07-05: archival lives in OpenRegister; procest defers. No exception ADR
exists for procest's app-local archival chain, and none is being requested — this change retires it.

## Why

Procest ships a five-service app-local archival/e-Depot chain with **zero** OpenRegister references
(verified 2026-07-05: `grep -c OpenRegister` returns 0 on each):

| Procest service | Lines | What it does |
|---|---|---|
| `lib/Service/ArchivalTriggerService.php` | 486 | Walks closed cases; matches `BewaarTermijnRegel` per zaaktypeKey; drives `OverdrachtTrigger` state machine (gereed / geblokkeerd-geen-regel / opgeschort-juridische-procedure on active bezwaar/beroep) |
| `lib/Service/ArchivalBatchService.php` | 329 | Bulk handover of closed cases; batch state machine; dispatch via the bound `EDepotSubmissionAdapter` |
| `lib/Service/BagItBundlerService.php` | 135 | In-memory BagIt 1.0 (RFC 8493) bundle for a `SipBundel` |
| `lib/Service/ArchivalSubmissionRetryService.php` | 267 | Durable retry queue over `OverdrachtTransactie` rows; exponential backoff 1m→8h; escalation to DIV audit trail after 5th failure |
| `lib/Service/ArchiefEdepotSeedDataService.php` | 134 | Seeds VNG default `BewaarTermijnRegel` rows (omgevingsvergunning 5y, wmo-melding 10y, subsidie-verlening permanent) |

plus six app-local schemas in `lib/Settings/register.d/62-archief-edepot.json`
(`bewaarTermijnRegel`, `overdrachtTrigger`, `sipBundel`, `overdrachtTransactie`, `archiefBewijs`,
`overdrachtAuditLog`) and two adapter seams (`External/Tmlo/EDepotSubmissionAdapterInterface` →
`LogEDepotSubmissionAdapter`; `External/Tmlo/TmloMetadataBuilderAdapterInterface` →
`LogTmloMetadataBuilderAdapter`, both bound in `lib/AppInfo/Application.php`).

OpenRegister on `origin/development` now owns this whole domain (verified per file via
`git -C ../openregister show origin/development:<path>`):
`lib/Service/RetentionService.php` (archiefactiedatum calculation, **selectielijst lookup**, legal
hold, destruction coordination), `lib/Service/Archival/` (RetentionEvaluator,
ArchiefactiedatumCalculator, LegalHoldService, DestructionService, ArchivalAnnotationValidator for
the `x-openregister-archival` schema dialect), `lib/BackgroundJob/DestructionCheckJob.php` +
`DestructionExecutionJob.php`, `lib/Service/TmloService.php` (TMLO metadata, MDTO export),
`lib/Service/Edepot/` (EdepotTransferService with in-flow retry, SipPackageBuilder (OAIS/ISO
14721), MdtoXmlGenerator (MDTO 1.0+), TransferListService, and a pluggable transport seam:
`Transport/TransportInterface` with Sftp/RestApi/OpenConnector transports), plus
`lib/Controller/ArchivalController.php`, `RetentionController.php`, `TmloController.php`,
`Settings/EdepotSettingsController.php`.

Running both stacks means duplicate retention rules, duplicate SIP builders, duplicate audit
trails, and a procest e-Depot pipeline that OR's archivist views and destruction workflow cannot
see. ADR-022 forbids exactly this.

## What Changes

- Retention terms + TMLO/MDTO mapping become declarative config on the `case` schema
  (`x-openregister-archival` + `configuration.tmloDefaults`; register `tmloEnabled`).
- Bezwaar/beroep suspension becomes an OpenRegister legal hold placed/released by a new
  `BezwaarLegalHoldListener`; beschikking archival is repointed onto an OR-backed adapter.
- A fail-closed, idempotent repair step (`MigrateArchivalToOpenRegister`) migrates in-flight state
  (enables TMLO, places holds for suspended cases, exports proof records to the zaakdossier).
- The app-local archival chain is retired: 8 services, 6 `External/Tmlo` adapter files,
  `ArchiefController` + its routes, 2 console commands, 3 background jobs, the seed repair step, the
  6 `62-archief-edepot.json` schemas, and the 4 Archief Vue views + their wiring, plus the
  corresponding unit tests.

## Per-service decision (duplicate vs domain-specific)

| Procest asset | Decision | Evidence |
|---|---|---|
| `ArchivalTriggerService` rule-matching + archiefactiedatum | **Retire** → OR `RetentionService` + `Archival/RetentionEvaluator` + `ArchiefactiedatumCalculator`; retention declared via `x-openregister-archival` on the case schema | OR owns rule evaluation + selectielijst lookup (RetentionService.php:153–155) |
| `ArchivalTriggerService` bezwaar/beroep suspension | **Keep the domain trigger, consume OR** — procest places/releases a legal hold via OR `Archival/LegalHoldService` when a bezwaar/beroep is registered/closed; the *hold mechanics* are OR's | Awb-procedure knowledge is procest domain; hold storage/enforcement is OR's |
| `ArchivalBatchService` | **Retire** → OR `Edepot/EdepotTransferService` + `TransferListService` (batching, status tracking, audit logging) | OR docblock: "full e-Depot transfer pipeline: SIP building, transport, status tracking, audit trail" |
| `BagItBundlerService` | **Retire in procest; file OR-side delta** — OR `SipPackageBuilder` builds OAIS SIPs but has no BagIt serialization (`git grep -i bagit` on OR origin/development: no hits). If the target e-Depot requires BagIt (RFC 8493), that is a *generic packaging format*, not procest domain — it belongs in OR's SipPackageBuilder as an output format option | OR-side delta requirement OR-AD-1 below |
| `ArchivalSubmissionRetryService` | **Retire in procest; file OR-side delta** — OR's `EdepotTransferService::sendWithRetry` retries in-flow (30s/120s/480s, EdepotTransferService.php:62–66) but has no *durable, cross-request* retry queue with long-horizon backoff (1m→8h) and escalation. That is generic transfer reliability, not procest domain | OR-side delta requirement OR-AD-2 below |
| `ArchiefEdepotSeedDataService` + `bewaarTermijnRegel` schema | **Migrate as config** — the VNG default retention terms per zaaktype are procest domain *data*; they move into `x-openregister-archival` retention annotations on procest's case schema / selectielijst configuration consumed by OR's RetentionService. The seeding *service* retires | Rules are domain config; the engine is OR's |
| TMLO/MDTO metadata mapping (`TmloMetadataBuilderAdapterInterface` + Log adapter) | **Retire the seam; contribute mapping as config** — OR `TmloService` auto-populates TMLO defaults from schema/register configuration and exports MDTO XML. Procest contributes its zaak→TMLO/MDTO field mapping declaratively (schema-level archival config), not as an adapter | OR TmloService docblock: "Auto-population of TMLO defaults from schema/register configuration" |
| `EDepotSubmissionAdapterInterface` + `LogEDepotSubmissionAdapter` | **Retire in favour of OR's transport seam** — the pluggable submission boundary moves to OR `Edepot/Transport/TransportInterface` (Sftp/RestApi/OpenConnector implementations exist). The seam **stays pluggable**; wiring a *real* e-Depot test endpoint belongs to `external-integrations-test-environments`, not this change | Transport seam verified on OR origin/development |
| `Beschikking/ArchivalAdapterInterface` + `MockArchivalAdapter` | **Repoint** — beschikking archival routes into the same OR archival pipeline instead of a procest-local adapter | Bound to mock in `Application.php:298` |
| `EmailArchivalService` | **Keep** — records linked emails as `caseDocument` (ZGW informatieobject) entries; it is zaakdossier registry logic, not retention/destruction/e-Depot machinery | Its docblock: "Lightweight archival surface … PDF conversion delegated to Docudesk" |
| Schemas `overdrachtTrigger`, `sipBundel`, `overdrachtTransactie`, `overdrachtAuditLog` | **Retire** — superseded by OR-side transfer/status/audit records (EdepotTransferService status tracking + OR audit trail, DestructionList Db entities) | Duplicate bookkeeping of OR's pipeline state |
| Schema `archiefBewijs` (proof of transfer) | **Retire in procest; verify OR equivalent** — ingest confirmation/checksums belong with OR's transfer result records; if OR's status tracking lacks a durable proof-of-transfer artefact, extend OR (OR-AD-3) | Proof-of-transfer is generic e-Depot pipeline output |

## OR-side delta requirements (filed on OR, not kept app-local)

- **OR-AD-1**: `SipPackageBuilder` SHOULD offer BagIt 1.0 (RFC 8493) serialization as an output
  format option where a target e-Depot requires it.
- **OR-AD-2**: e-Depot transfers need a durable retry queue (long-horizon exponential backoff,
  append-only attempt records, escalation after N failures) beyond the in-flow 30s/120s/480s retry.
- **OR-AD-3**: a durable proof-of-transfer record (ingest confirmation, archivId, checksums) if not
  already covered by OR transfer status tracking — verify before filing.

These are consumed as dependencies here; authoring them is OR-side work referenced by this change,
not procest code.

## Data / config migration path

1. Retention rules: existing `bewaarTermijnRegel` objects (and the VNG seed defaults) are
   translated into `x-openregister-archival` retention configuration on procest's case schema
   (+ selectielijst mapping consumed by OR RetentionService) via a one-shot repair step; the old
   objects are archived read-only until verification, then removed with the schema.
2. In-flight `overdrachtTrigger`/`overdrachtTransactie` rows: cases already `gereed-voor-overdracht`
   or mid-retry are re-nominated through OR's pipeline by the same repair step; completed
   `archiefBewijs` records are exported to the zaakdossier (Files) before schema retirement so no
   proof of transfer is lost.
3. e-Depot connection config (`eDepotConnectionId`, channel settings) moves to OR's e-Depot
   settings (`Settings/EdepotSettingsController` surface).
4. The `62-archief-edepot.json` fragment shrinks to only what procest still owns (nothing, if
   migration completes; the fragment is then removed).

## Impact

- Removes ~1,350 lines of procest services + 6 schemas + 2 adapter seams.
- `lib/AppInfo/Application.php`: unregister the retired bindings.
- Procest UI that surfaced trigger/batch state (if any) repoints to OR's archivist views.
- `openspec/features.overlay.json`: "Archivering naar e-Depot" gains `providedBy: openregister`
  semantics for the pipeline; see `align-claims-and-licence` for the status downgrade rationale.
- Depends on OR `origin/development` archival stack being in the deployed OR release.

## Out of Scope

- Wiring a real e-Depot endpoint (test or production) — `external-integrations-test-environments`.
- Destruction/vernietiging UI — OR's archivist surface owns it.
- The Awb bezwaar/beroep detection logic itself (stays; only its hold mechanism changes).
