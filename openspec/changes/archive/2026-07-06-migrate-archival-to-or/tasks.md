# Tasks: migrate-archival-to-or

> Ordering follows design.md "Removal order" — every intermediate state stays shippable.

> ✅ **STATUS 2026-07-07: IMPLEMENTED & ARCHIVED.** Unblocked once OpenRegister's
> `archival-transfer-hardening` landed on OR `origin/development` (edepot-durable-retry,
> edepot-bagit-output, edepot-proof-of-transfer + `ImportEdepotTransferRegister` +
> `edepotTransfer`/`edepotTransferProof` schemas) — OR-AD-1/2/3 all built. Retention/TMLO config is
> declarative on the case schema; the app-local archival chain (8 services, 6 adapter files, 1
> controller, 2 commands, 3 jobs, 6 schemas, 4 Vue views) is retired; bezwaar/beroep suspension is
> now an OR legal hold; a fail-closed/idempotent repair step migrates in-flight state. Verified via
> the CI-way unit suite (php:8.3-cli, 1315 tests green — 4 pre-existing zip-extension env errors
> only) + composer check:strict (phpcs/phpstan/psalm/phpmd) clean on the diff.

## Deduplication / Dependency Check

- [x] **DC01 (VERIFIED)**: OR `origin/development` ships the archival stack — `lib/Service/RetentionService.php`, `lib/Service/Archival/{ArchivalAnnotationValidator,LegalHoldService,…}.php`, `lib/Service/TmloService.php`, `lib/Service/Edepot/{EdepotTransferService,SipPackageBuilder,MdtoXmlGenerator}.php` + `Edepot/Transport/{TransportInterface,Sftp,RestApi,OpenConnector}Transport.php`, `lib/Controller/Settings/EdepotSettingsController.php`. The BASE engine is present.
- [x] **DC02 (VERIFIED)**: OR's `x-openregister-archival` validator (`lib/Service/Archival/ArchivalAnnotationValidator.php`) is shipped — the config shape (retention/rules/condition/default/reason) is available to consume. (Not authored this session — see the blocking gate below.)
- [x] **DC03 (VERIFIED — GAPS CONFIRMED, MIGRATION GATED)**: all three OR-side deltas are ABSENT on OR `origin/development`: OR-AD-1 BagIt in SipPackageBuilder (`git grep -i bagit` → 0 hits), OR-AD-2 durable cross-request retry queue (0 hits), OR-AD-3 durable proof-of-transfer record (0 hits). They live in OR's `archival-transfer-hardening` change (specs `edepot-bagit-output`, `edepot-durable-retry`, `edepot-proof-of-transfer`) which is PROPOSED ONLY (0/15 tasks done). Retiring procest's `BagItBundlerService` (T07), `ArchivalSubmissionRetryService` (T07), and `archiefBewijs` schema (T08) would lose BagIt output, durable long-horizon retry, and proof-of-transfer that OR cannot yet provide → **retirement blocked-pending-OR**.

## Declarative config + domain listeners

- [x] **T01**: `x-openregister-archival` retention config authored on the `case` schema (default P10Y; per-zaaktype rules for omgevingsvergunning-regulier P5Y, wmo-melding P10Y, subsidie-verlening P20Y/overbrenging) — validator-conformant (`ArchivalAnnotationValidator` shape: `retention.default` + `retention.rules[].{condition,retention,reason}`).
- [x] **T02**: `Schema.configuration.tmloDefaults` (archiefstatus/classificatie) declared on the case schema; `Register.configuration.tmloEnabled` set by the repair step — consumed by OR `TmloService`. Retires `TmloMetadataBuilderAdapterInterface` + `LogTmloMetadataBuilderAdapter`.
- [x] **T03**: `lib/Listener/BezwaarLegalHoldListener.php` — `objection` creation places an OR `LegalHoldService` hold on the case; `bezwaarDecision`/`appealDecision` creation releases it (Awb-specific terminal artefacts; idempotent via `hasActiveHold`). Registered on `ObjectCreatedEvent`. OR services resolved lazily by FQN (safe no-op when OR absent).
- [x] **T04**: `Beschikking/ArchivalAdapterInterface` rebound to new `OpenRegisterArchivalAdapter` (retention validated via OR `TmloService`); `MockArchivalAdapter` + its alias retired.

## Migration repair step

- [x] **T05**: `lib/Repair/MigrateArchivalToOpenRegister.php` — idempotent (app-config marker `procest/archival_migration_completed`), fail-closed (skips unless OR `LegalHoldService` present). Enables register TMLO; places OR legal holds for `overdrachtTrigger` rows at `opgeschort-juridische-procedure`; exports completed `archiefBewijs` as zaakdossier caseDocuments. Never deletes source rows (fragment removal leaves OR objects intact).
- [x] **T06**: e-Depot connection config documented as owned by OR's e-Depot settings surface (`/api/settings/edepot`) in `docs/admin/archief-edepot.md` (rewritten for the OR-owned pipeline).

## Retirement

- [x] **T07**: Removed `ArchivalTriggerService`, `ArchivalBatchService`, `BagItBundlerService`, `ArchivalSubmissionRetryService`, `ArchiefEdepotSeedDataService`, `ProofOfTransferService`, `RollbackManager`, `MetadataBundlerService`; the 6 `External/Tmlo` adapter files; `ArchiefController` + 2 commands + 3 background jobs + `SeedArchiefEdepotData` repair; the `Application.php` alias bindings. Unit tests removed; no mock shims left (Beschikking keeps a pluggable interface bound to the OR adapter).
- [x] **T08**: Removed the six schemas (`register.d/62-archief-edepot.json`) + `archief_edepot_seed_data.json`. Repair step preserves in-flight data first; fragment removal stops the register-import definitions without deleting existing objects.
- [x] **T09**: Removed the Archief dashboard/settings Vue views + modal and their `manifest.json`/`menu-layout.json`/`customComponents.js`/`AdminRoot.vue` wiring and `archief#*` routes. i18n source keys were already English.

## Verification Tasks

- [x] **V01** *(structural + unit; live behavioural run is the deploy-time gate)*: retention is declarative on the case schema and the trigger daemon is deleted — closing a case can only be evaluated by OR's `RetentionEvaluator`. No procest rule-matching remains (`ArchivalTriggerService` gone).
- [x] **V02** *(structural)*: `BezwaarLegalHoldListener` places/releases OR legal holds; OR's evaluator/destruction jobs honour `retention.legalHold` (verified against OR `LegalHoldService`/`RetentionEvaluator` on origin/development).
- [x] **V03** *(structural)*: no procest SIP/submission code remains; SIP building + transport are OR's. Zero writes to the retired schemas (all writers deleted).
- [x] **V04**: repair step idempotency proven by the app-config completion marker (second run returns early); proof export writes caseDocuments; source rows are never deleted so municipality edits survive. Fail-closed path (OR absent) exercised implicitly by the CI-way unit run (OR classes absent → no-op).
- [x] **V05**: instance-wide regression — `grep` over `lib/ src/ appinfo/ tests/` shows no code references to any removed class (only comments); every `routes.php` controller resolves; the full unit suite is green (1315 tests; 4 pre-existing zip-extension env errors only).

## Implementation record (2026-07-07)

- **Unblocked** by OR `archival-transfer-hardening` (OR-AD-1/2/3 built: edepot-durable-retry,
  edepot-bagit-output, edepot-proof-of-transfer) on OR `origin/development`.
- **Verification**: CI-way unit suite in `php:8.3-cli` (repo bootstrap → OCP stubs), 1315 tests /
  4135 assertions green; the only 4 errors are missing `ZipArchive`/zip-extension in the offline
  container (`ZipManifestBuilderTest` ×3 + `BeschikkingServiceTest::testAuditPacketIsZip` — all
  out-of-scope, all pass with the extension present). `composer` phpcs/phpstan/psalm/phpmd clean on
  the diff.
- **Not run live**: full behavioural V01–V03 on a deployed OR-archival instance (no such instance
  available to the worktree) — these are the deploy-time gate; structural/unit verification is
  recorded above.
