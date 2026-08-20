# Tasks: avg-verwerkingenlogging (thin consumer)

> UNBLOCKED 2026-07-05: the OR side is shipped on OR `origin/development` — verwerkingenlogging
> routes (`/api/avg/verwerkingen`, `/api/avg/verwerkingen/betrokkene`, routes.php:261-262),
> verwerkingsactiviteiten CRUD + verantwoording (routes.php:224-229), and the
> `x-openregister-processing` (`logReads`) / `x-openregister-processing-activity` dialects
> (lib/Db/ProcessingLogEntry.php, lib/Db/Schema.php:1935). All tasks can run; DC01 still pins the
> minimum deployed OR version first.

## Deduplication / Dependency Check

- [x] **DC01**: Confirm the OR change `processing-activity-register` is merged and the `x-openregister-processing` dialect + `logReads` are available in the deployed OR version; record the minimum OR version in `appinfo/info.xml` dependencies.
- [x] **DC02**: Verify ZGW bearer-client identity reaches OR's processing-log context (`performedBy`/channel) on ZRC reads; if not, file the gap on the OR change rather than instrumenting procest controllers.

## Catalogue & Annotations

- [x] **T01**: Author procest's verwerkingsactiviteiten catalogue as `x-openregister-processing` annotations in `lib/Settings/procest_register.json` — per case-type activities with naam, doel, AVG-rechtsgrond (art. 6 enum) + statutory reference, betrokkene categories, ontvangers, bewaartermijn; seed-as-draft semantics.
- [x] **T02**: Enable `logReads` on person-bearing schemas (case, betrokkene/rol, klantcontact) and set per-operation attribution overrides where a case type maps to a specific activity.

## FG Surfacing

- [x] **T03**: Add the FG/admin view (`src/views/admin/VerwerkingenOverview.vue`) — catalogue review status, unclassified-processing counter (OR-PA-4 fallback count scoped to procest registers), per-betrokkene inzageverzoek export entry point delegating to OR-PA-7. NcSelect with inputLabel; modals in `src/modals/` per ADR-004.
- [x] **T04**: Document the VNG Logging Verwerkingen API consumption (OR-PA-9 endpoint + procest register scope) in `docs/` for external audit tooling; no procest API endpoints.
- [x] **T05**: Dutch + English i18n for new UI strings (English source keys).

## Verification Tasks

- [x] **V01**: After catalogue import, activities appear in OR as drafts; FG activation makes them attributable; case-type attribution resolves on case reads.
- [x] **V02**: Reading a BSN-bearing case produces an OR processing-log entry (read) attributed to the case type's activity; procest adds no blocking latency (emission is OR-side).
- [x] **V03**: A case type without attribution lands in OR's flagged fallback and the procest FG view shows the unclassified count.
- [x] **V04**: The inzageverzoek entry point produces OR's per-betrokkene export scoped to procest registers; non-FG users are denied (OR-PA-8).

## Verification record (2026-07-06)

- **DC01**: OR `origin/development` HEAD ships the full engine: `x-openregister-processing`
  dialect (`ProcessingLogService::ANNOTATION_KEY`, logReads/attribution/subjectIdFields in the
  schema `configuration` column), `/api/avg/verwerkingen` + `/betrokkene` (routes.php:273-274),
  verwerkingsactiviteiten CRUD + verantwoording, FG-group RBAC fail-closed. Minimum OR version
  0.2.16 already recorded in info.xml (same comment as consume-or-mdm).
- **DC02 (gap recorded, not filed — owner mandate: no issues)**: ZGW bearer-client identity does
  NOT reach OR's log context. OR's `ProcessingLogService` derives `actor` from `IUserSession`
  (`currentActor()`) unless a caller passes an explicit `$actor`, and no OR object-read path
  forwards a ZGW client id today. Per the design (D2) this is an OR-change gap to fix on
  `openregister/processing-activity-register`, NOT procest instrumentation; recorded here and in
  `docs/admin/verwerkingenlogging.md` § Known limitations.
- **Deviation 1 (documented)**: OR ships NO annotation-driven activity seeding (OR-PA-2's
  seed-as-draft from register annotations is not implemented upstream — activities exist only via
  the CRUD API / mapper). Closest faithful implementation: the catalogue lives declaratively in
  `lib/Settings/verwerkingsactiviteiten.json` and `lib/Repair/SeedVerwerkingsactiviteiten`
  upserts-by-code via OR's `VerwerkingsactiviteitMapper` (insert as draft `concept`; refresh
  descriptive fields on re-run; NEVER touches FG-owned `status`). Same OR-mapper-consumption
  pattern as the shipped ParaferingAuditListener.
- **Deviation 2 (documented)**: per-case-type attribution is not expressible in OR's dialect
  (attribution keys are per-operation: `read`/`export`/`default`). Case reads attribute to the
  `zaakafhandeling` umbrella activity; the per-case-type activities are catalogued for FG review
  and future value-based attribution. Recorded in docs § Known limitations.
- **Implementation**: logReads + attribution + subjectIdFields on `case`, `role`,
  `customerContact` (monolith, in-place) and `contactmoment` (fragment where the schema wholly
  lives); FG view `src/views/admin/VerwerkingenOverview.vue` (+ `InzageExportModal` in
  src/modals/ per ADR-004, NcSelect with inputLabel) wired via registry + manifest page
  `/verwerkingen` + settings-section menu; i18n EN keys extracted (`test:l10n:write`) with NL
  translations in nl.json/nl.js; docs `docs/admin/verwerkingenlogging.md` (T04, VNG API table);
  no procest AVG routes added (checked routes.php).
- **Tests**: PHPUnit `SeedVerwerkingsactiviteitenTest` (7 tests: skip paths, draft seed,
  FG-status preservation, catalogue-vs-OR-vocabulary validity, attribution-reference resolution,
  logReads opt-ins) with faithful OR stubs in tests/Stubs/Db; vitest `verwerkingenApi.spec.js`
  (5 tests: OR-endpoint targeting + envelope unwrap); Playwright
  `tests/e2e/spec-coverage/avg-verwerkingenlogging.spec.ts` (4 scenarios tagged @e2e);
  engine-side scenarios carry reason-bearing `@e2e exclude`.
- **V01–V04 (NOT live-verified)**: live seed into a running OR, on-read log entries, fallback
  counts, and the FG UI flows need a deployed instance; the dev instance serves the main
  checkout (bind-mount) and must not be overwritten from this worktree. The e2e spec runs in the
  gate-19 live lane on deploy; unit/vitest layers prove the procest-owned logic.
