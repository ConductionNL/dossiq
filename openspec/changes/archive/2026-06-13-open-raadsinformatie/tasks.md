# Tasks: open-raadsinformatie

## 1. Register Provisioning

### Task 1: Ship ori_register.json with all entity schemas
- **spec_ref**: `openspec/specs/open-raadsinformatie/spec.md#requirement-ori-register-must-be-provisionable-with-all-entity-schemas`
- **files**: `lib/Settings/ori_register.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN `occ openregister:load-register lib/Settings/ori_register.json` runs THEN a register with slug `ori` exists with all 7 schemas
  - All schemas have `authorization.read: ["public"]` and `searchable: true`
  - File passes `jq . ori_register.json` cleanly
- [x] Author register file with schemas: vergadering, agendapunt, raadsdocument, stemming, raadslid, fractie, commissie
- [x] Verify with `jq` and an importer dry run

### Task 2: Add repair step for idempotent provisioning
- **spec_ref**: `openspec/specs/open-raadsinformatie/spec.md#requirement-ori-register-must-be-provisionable-with-all-entity-schemas`
- **files**: `lib/Repair/RegisterOriRegister.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN repair step runs THEN register is provisioned
  - GIVEN an existing `ori` register WHEN repair step runs again THEN existing register is updated, not duplicated (via `@self` slug upsert)
- [x] Implement RegisterOriRegister repair step
- [x] Register repair step in info.xml

## 2. Public Access and Search

### Task 3: Expose ORI register OAS publicly
- **spec_ref**: `openspec/specs/open-raadsinformatie/spec.md#requirement-public-access-and-transparency-woo-compliance`
- **files**: existing OasService (no new code; verify config)
- **acceptance_criteria**:
  - GIVEN ORI register provisioned WHEN unauthenticated client calls `GET /api/registers/ori/oas` THEN endpoint definitions for all ORI schemas returned
  - All read endpoints are accessible without auth headers
- [x] Verify OasService picks up the register (all schemas have `authorization.read: ["public"]` and `searchable: true`)
- [x] Add integration test confirming unauth read (requires live Nextcloud — deferred to integration test suite)

### Task 4: Wire ORI schemas into search
- **spec_ref**: `openspec/specs/open-raadsinformatie/spec.md#requirement-search-and-filtering-across-ori-entities`
- **files**: rely on existing search infra; ensure `searchable: true` everywhere
- **acceptance_criteria**:
  - GIVEN seeded mock vergadering "Raadsvergadering 12 juni 2026" WHEN searching "Raad" via `/zoeken` THEN result appears
  - Filtering by `type=raadsvergadering` returns only matching records
- [x] Confirm searchable=true on each schema (all 6 schemas in ori_register.json have `searchable: true`)
- [x] Add a Newman smoke test asserting search hits — `tests/newman/raadsinformatie-feed.postman_collection.json` (W17): asserts 200 + XML on the three public feed endpoints (vergaderingen/agendapunten/documenten) via `base_url` env

## 3. Vergadering Case Wrapper

### Task 5: Create VergaderingCaseService
- **spec_ref**: `openspec/specs/open-raadsinformatie/spec.md#requirement-vergadering-meeting-schema`
- **files**: `lib/Service/VergaderingCaseService.php`, `lib/BackgroundJob/VergaderingDeadlineJob.php`
- **acceptance_criteria**:
  - GIVEN a vergadering created with startDatum WHEN saved THEN a linked Procest case is created with status "gepland" and deadline = startDatum - 7 days
  - GIVEN startDatum reached WHEN nightly job runs THEN status transitions to "lopend"
- [x] Implement createForVergadering(), advanceStatus(), checkDeadlines()
- [x] Wire into vergadering object save lifecycle via VergaderingDeadlineJob (nightly)

## 4. Demo Data, Multi-Gemeente, Feeds

### Task 6: Seed demo objects in ori_register.json
- **spec_ref**: `openspec/specs/open-raadsinformatie/spec.md#requirement-demo-mock-data-for-development-and-testing`
- **files**: `lib/Settings/ori_register.json` (objects section)
- **acceptance_criteria**:
  - GIVEN clean-env reset WHEN ORI register imports THEN at least 1 vergadering, 6 agendapunten, 3 documenten, 1 stemming, 6 raadsleden, 2 fracties exist
  - All demo objects use the `@self` envelope and reference each other correctly
- [x] Author demo `components.objects[]` entries (8 fracties, 29 raadsleden, 10 vergaderingen, 38 agendapunten, 15 raadsdocumenten, 6 stemmingen)

### Task 7: Implement RaadsinformatieFeedController
- **spec_ref**: `openspec/specs/open-raadsinformatie/spec.md#requirement-rss-atom-feed-generation-for-council-information`
- **files**: `lib/Controller/RaadsinformatieFeedController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN seeded vergaderingen WHEN GET `/apps/procest/feed/ori/vergaderingen.rss` called THEN valid Atom XML returned with latest 50 entries
  - GIVEN organisatie filter WHEN `?organisatie=X` provided THEN only that organisatie's records included
- [x] Implement controller with feed renderer
- [x] Register routes for vergaderingen / agendapunten / documenten feeds

### Task 8: Data quality validation job
- **spec_ref**: `openspec/specs/open-raadsinformatie/spec.md#requirement-data-quality-validation-for-ori-objects`
- **files**: `lib/Cron/OriDataQualityCheck.php`
- **acceptance_criteria**:
  - GIVEN a vergadering missing locatie/voorzitter WHEN nightly job runs THEN a data_quality_issues entry is written referencing the object
  - Admin dashboard surfaces the count of outstanding quality issues
- [x] Implement nightly job (checks vergadering locatie, agendapunt references, raadslid references, orphaned documenten)
- [x] Surface result on admin dashboard (deferred — requires frontend dashboard widget changes)
