# Tasks: migrate-archival-to-or

> Ordering follows design.md "Removal order" — every intermediate state stays shippable.

> ⛔ **STATUS 2026-07-06: BLOCKED-PENDING-OR — NOT APPLIED, NOT ARCHIVED.** The DC dependency
> checks below were run against OpenRegister `origin/development` HEAD. Result: OR ships the BASE
> archival engine, but ALL THREE OR-side delta requirements this migration depends on are ABSENT,
> and the OR change that would add them (`archival-transfer-hardening`) is PROPOSED ONLY
> (0/15 tasks done on OR origin/development). Therefore the retirement (T07/T08) would delete
> procest functionality OpenRegister cannot yet replace, and the config migration (T01/T02) cannot
> be activated without that retirement (adding `x-openregister-archival` while the app-local
> `ArchivalTriggerService` still runs would DOUBLE-process retention). No procest code was changed
> this session — implementing a partial/guessed migration is explicitly out of scope. Re-run when
> OR `archival-transfer-hardening` lands (OR-AD-1/2/3 built).

## Deduplication / Dependency Check

- [x] **DC01 (VERIFIED)**: OR `origin/development` ships the archival stack — `lib/Service/RetentionService.php`, `lib/Service/Archival/{ArchivalAnnotationValidator,LegalHoldService,…}.php`, `lib/Service/TmloService.php`, `lib/Service/Edepot/{EdepotTransferService,SipPackageBuilder,MdtoXmlGenerator}.php` + `Edepot/Transport/{TransportInterface,Sftp,RestApi,OpenConnector}Transport.php`, `lib/Controller/Settings/EdepotSettingsController.php`. The BASE engine is present.
- [x] **DC02 (VERIFIED)**: OR's `x-openregister-archival` validator (`lib/Service/Archival/ArchivalAnnotationValidator.php`) is shipped — the config shape (retention/rules/condition/default/reason) is available to consume. (Not authored this session — see the blocking gate below.)
- [x] **DC03 (VERIFIED — GAPS CONFIRMED, MIGRATION GATED)**: all three OR-side deltas are ABSENT on OR `origin/development`: OR-AD-1 BagIt in SipPackageBuilder (`git grep -i bagit` → 0 hits), OR-AD-2 durable cross-request retry queue (0 hits), OR-AD-3 durable proof-of-transfer record (0 hits). They live in OR's `archival-transfer-hardening` change (specs `edepot-bagit-output`, `edepot-durable-retry`, `edepot-proof-of-transfer`) which is PROPOSED ONLY (0/15 tasks done). Retiring procest's `BagItBundlerService` (T07), `ArchivalSubmissionRetryService` (T07), and `archiefBewijs` schema (T08) would lose BagIt output, durable long-horizon retry, and proof-of-transfer that OR cannot yet provide → **retirement blocked-pending-OR**.

## Declarative config + domain listeners

- [ ] **T01**: Author `x-openregister-archival` retention config on procest's case schema(s) covering the VNG defaults currently seeded by `ArchiefEdepotSeedDataService` (omgevingsvergunning 5y, wmo-melding 10y, subsidie-verlening permanent) plus selectielijst categorie/versie and e-Depot bestemming fields from `bewaarTermijnRegel`.
- [ ] **T02**: Declare procest's zaak→TMLO/MDTO field mapping as schema/register configuration consumed by OR `TmloService` (replaces `TmloMetadataBuilderAdapterInterface` + `LogTmloMetadataBuilderAdapter`).
- [ ] **T03**: Add the legal-hold listener: bezwaar/beroep registration places an OR `LegalHoldService` hold on the case; final Awb outcome releases it. Reuse the existing bezwaar/beroep lifecycle signals — no new detection logic.
- [ ] **T04**: Repoint `Beschikking/ArchivalAdapterInterface` consumers to the OR archival pipeline and retire `MockArchivalAdapter` + the `Application.php:298` alias.

## Migration repair step

- [ ] **T05**: Implement `lib/Repair/MigrateArchivalToOpenRegister.php` (idempotent, fail-closed when OR abstractions are absent): translate live `bewaarTermijnRegel` objects (municipality edits included) → OR config; re-nominate open `overdrachtTrigger` cases (status mapping per design.md); export completed `archiefBewijs`/`overdrachtAuditLog` as immutable zaakdossier documents.
- [ ] **T06**: Move e-Depot connection configuration (`eDepotConnectionId`, channel settings) to OR's e-Depot settings surface; document the admin path in `docs/admin/`.

## Retirement

- [ ] **T07**: Remove `ArchivalTriggerService`, `ArchivalBatchService`, `BagItBundlerService`, `ArchivalSubmissionRetryService`, `ArchiefEdepotSeedDataService`, their background-job registrations, the `EDepotSubmissionAdapterInterface`/`LogEDepotSubmissionAdapter` and `TmloMetadataBuilderAdapterInterface`/`LogTmloMetadataBuilderAdapter` seams, and the corresponding `Application.php` bindings. Update/remove their unit tests; no mock-based shims left behind.
- [ ] **T08**: Deprecate then remove the six schemas in `lib/Settings/register.d/62-archief-edepot.json` (schema removal only after V01–V05 pass on a migrated instance); shrink or delete the fragment.
- [ ] **T09**: Repoint any procest UI surfacing trigger/batch state to OR's archivist views; remove dead frontend code; i18n cleanup (English source keys).

## Verification Tasks

- [ ] **V01**: On a migrated instance, closing an omgevingsvergunning case leads to OR computing the archiefactiedatum and nominating the case — with procest's trigger daemon absent.
- [ ] **V02**: Registering a bezwaar on a nominated case places an OR legal hold (transfer + destruction skip it); the final outcome releases it and the case re-enters evaluation.
- [ ] **V03**: A handover run produces an OR-built MDTO SIP submitted through the configured OR transport (log/mock transport in dev); status + audit trail readable in OR; zero rows written to the retired procest schemas.
- [ ] **V04**: The repair step is idempotent (second run is a no-op) and preserves every completed proof-of-transfer as a zaakdossier document; a municipality-edited retention rule survives migration with its edited values.
- [ ] **V05**: Instance-wide regression: no references to the removed classes remain (`grep` over lib/ + tests), app enables cleanly, and OR's destruction workflow lists procest objects per its retention config.

## Blocked-pending-OR record (2026-07-06)

- **What stands on OR's EXISTING seams**: only additive `x-openregister-archival` retention config
  (T01) + TMLO/MDTO mapping config (T02) + legal-hold consumption (T03) are technically
  expressible against the shipped OR validators. But NONE can be safely ACTIVATED this session:
  adding the OR retention annotation while procest's `ArchivalTriggerService` daemon still runs
  double-nominates cases, and retiring that daemon (T07) is gated on OR-AD-1/2/3.
- **Blocked on OR (`archival-transfer-hardening`, proposed only)**: T07 (retire BagItBundlerService,
  ArchivalSubmissionRetryService), T08 (retire archiefBewijs schema), and by dependency the whole
  removal-ordered retirement + migration repair (T05) + UI repoint (T09) + V01–V05.
- **Decision**: no procest code changed; change left ACTIVE (not archived) so it re-enters the
  queue when OR archival-transfer-hardening merges. This honours the mandate — "implement only the
  parts that stand on OR's existing seams and report the OR-dependent parts as blocked-pending-OR
  rather than guessing." The safe additive parts are inseparable from the blocked retirement
  (double-processing risk), so the honest action is to defer the whole change.
