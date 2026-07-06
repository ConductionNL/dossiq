# Tasks: migrate-archival-to-or

> Ordering follows design.md "Removal order" — every intermediate state stays shippable.

## Deduplication / Dependency Check

- [ ] **DC01**: Verify the deployed OR version ships the archival stack (RetentionService, Archival/RetentionEvaluator + LegalHoldService + DestructionService, DestructionCheckJob/DestructionExecutionJob, TmloService, Edepot/EdepotTransferService + SipPackageBuilder + MdtoXmlGenerator + Transport seam) — verify against OR HEAD (`git -C ../openregister show origin/development:<path>`), record minimum OR version in `appinfo/info.xml`.
- [ ] **DC02**: Validate the intended `x-openregister-archival` config shape against OR's `ArchivalAnnotationValidator` on the deployed version before authoring the migration (keys: retention/rules/condition/default/reason).
- [ ] **DC03**: Verify OR-side gaps and register them as OR delta requirements where confirmed: BagIt output in SipPackageBuilder (OR-AD-1), durable long-horizon retry queue (OR-AD-2), durable proof-of-transfer record (OR-AD-3). If OR already covers one, consume it and drop the delta.

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
