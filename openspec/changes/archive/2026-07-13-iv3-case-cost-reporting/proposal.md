# Proposal: iv3-case-cost-reporting

kind: code — new capability. Adds an IV3 (Informatie voor Derden) taakveld
classification to case types, a lightweight per-case cost record, and a
quarterly cost-reporting surface (JSON + CSV) so a municipality's controller
can assemble the per-taakveld cost breakdown CBS requires each quarter
directly from procest instead of hand-collating figures across systems.

## Why

Dutch municipalities must submit IV3 financial reports to CBS every quarter,
broken down by taakveld (the BBV functional-classification code, e.g. `8.1`
Ruimtelijke ordening, `7.4` Milieubeheer). No zaaksysteem competitor supplies
per-case cost data pre-classified by taakveld, so controllers currently
reconcile case-level leges income and handling cost against the financial
system by hand, per quarter, per taakveld — a manual burden this change
removes for any case type a municipality tags with a taakveld.

**Verified against `origin/development` HEAD before designing this change**
(no parallel financial subsystem invented):

- `caseType` (`lib/Settings/procest_register.json`, schema `caseType`,
  version `1.1.0`) has **no** taakveld/BBV classification field today.
- `case` (same file, schema `case`, version `1.7.0`) has **no** cost/amount
  field. It has `paymentIndication` (enum status only: `nvt`/`nog_niet`/
  `gedeeltelijk`/`geheel`) and `lastPaymentDate` — status flags, not amounts.
- Per commit `80a76d66d` ("retire the municipal-fee engine; fees are
  Pipelinq products, ADR-003"), procest deliberately removed its own fee
  engine: a case-type's `productsOrServices` references Pipelinq product
  objects, and "the charge on a concrete case is a Pipelinq financial
  transaction" living in Pipelinq, not procest. Pulling actual leges
  transaction amounts from Pipelinq would require a new cross-app HTTP
  client (the `WooPublicationService`/`OpenCatalogiApiClient` pattern) —
  out of scope here (no new composer deps, no cross-app coupling in this
  change). This change therefore adds a **lightweight, procest-local** cost
  record on `case` for IV3-reporting purposes only, explicitly documented as
  not a general ledger and not a replacement for Pipelinq's transaction
  data (see `design.md` "Cost data: existing fields vs new").
- `lib/Service/Subsidie/*` (SubsidieService, VaststellingService,
  TussenrapportageService) already track real money —
  `subsidieAanvraag.aangevraagdBedrag`, `subsidieUitvoering.werkelijkeKostenTotaal`,
  `subsidieVaststelling.vastgesteldBedrag` — but on **separate objects**
  (`subsidieAanvraag` → `subsidieUitvoering` → `subsidieVaststelling`, a
  3-hop chain) linked to a case only via `subsidieAanvraag.case`. Traversing
  that chain inside a generic per-case cost aggregation would turn a
  single-schema read into a multi-schema join and couple this change to the
  Subsidie domain's specific object graph. Left as a documented fast-follow
  (design.md) rather than folded into `Iv3ReportService` v1.
- `KpiAggregationService` aggregates via raw DB `JSON_EXTRACT` SQL;
  `TermijnReportingService` aggregates via `SearchesObjects` +
  `ObjectService::searchObjects()`. This change follows the
  `TermijnReportingService` shape (OpenRegister's own search API, not raw
  SQL) since IV3 aggregation needs a `case` → `caseType` join that
  `SearchesObjects` already supports cleanly, and `TermijnReportingService`
  is the closer sibling (quarterly financial report, CSV export, dashboard
  section) per the task brief.
- The existing quarterly-report CSV convention lives in
  `TermijnReportingService::quarterlyReportAsCsv()` (manual `implode(',')`,
  no escaping) — the more robust `fputcsv()`-based pattern from
  `AiAuditExportController::buildCsv()` (proper quoting, `DataDownloadResponse`)
  is followed instead, since it is the more recent, safer convention and
  already handles the same "financial CSV download, gated to a role group"
  shape this change needs.
- Auth: `AiAuditExportController`/`ParaferingAuditExportController` gate CSV
  export via `IGroupManager` against `ALLOWED_GROUPS` (falls back to
  `isAdmin()`); `SubstitutionController`/`ComplaintController` treat
  "coordinator" as a synonym for NC admin. This change reuses that same
  `IGroupManager`-based allow-list-plus-admin-fallback gate (not the plain
  "any authenticated user" gate `TermijnReportingController` currently uses)
  since IV3 figures are quarterly financial reporting data, the same
  sensitivity class as the AI/parafering audit exports.

## What Changes

- **Data model**: `caseType` gains optional `iv3Taakveld` (string, BBV
  taakveld code e.g. `"8.1"`); `case` gains optional `kosten` (JSON-encoded
  array of `{bedrag, type: leges_income|handling_cost, datum}` entries,
  following the existing `statusHistory`/`activity`/`begroting` JSON-string
  convention already used on `case`/`subsidieAanvraag`). Register + app
  version bumped per the established re-import convention.
- **NEW**: `lib/Settings/iv3_taakvelden.json` — versioned BBV taakveld code
  list (9 main categories `0`–`8`, ~55 subcodes), the one testable source of
  truth for valid codes, cross-checked against two independent published
  sources (CBS Iv3-model / vraagbaakiv3gemeenten.nl / gemeente
  jaarrekening publications — see design.md "Taakveld list source").
- **NEW**: `lib/Service/Iv3TaakveldList.php` — loads/validates/labels the
  taakveld list (single testable place).
- **NEW**: `lib/Service/Iv3ReportService.php` — pure, unit-testable quarterly
  aggregation: per taakveld, per quarter — case count, total recorded cost,
  total leges income, average cost per case; cases whose case type carries
  no taakveld are excluded from taakveld buckets and reported separately
  under `uncategorized`. CSV serialisation.
- **NEW**: `lib/Controller/Iv3ReportController.php` — `GET
  /api/reports/iv3?year=&quarter=&format=json|csv` and `GET
  /api/reports/iv3/taakvelden` (list, for the settings picker + report
  filter), gated to `ALLOWED_GROUPS` (+ admin fallback), mirroring
  `AiAuditExportController`.
- **UI**: `Iv3ReportDashboard.vue` — year/quarter picker, taakveld table, CSV
  download button — added to the existing "Reports" (`AnalyticsGroup`) nav
  section alongside `TermijnDashboard`/`Doorlooptijd`, via manifest v2
  `customComponents.js` + `menu-layout.json` (no new page-type infra).
  `GeneralTab.vue` (case-type settings) gains an `iv3Taakveld` `NcSelect`
  picker sourced from the new taakvelden endpoint, following the existing
  `confidentiality`/`origin` picker pattern. `case.kosten` itself gets no
  bespoke editor — it is a plain schema field and renders through procest's
  existing schema-driven generic object detail form like any other
  non-`visible:false` array field.
- **Tests**: PHPUnit for taakveld list integrity, aggregation (quarter
  boundaries, multiple taakvelden, uncategorized bucket, empty quarter), CSV
  shape, controller RBAC; Vitest for any pure frontend helper the dashboard
  needs (currency/quarter formatting, following `TermijnDashboard.vue`'s
  inline helpers — likely no new frontend module is warranted, confirmed
  during implementation).

## Impact

- Affected specs: `iv3-case-cost-reporting` (new capability spec).
- Affected code: `lib/Settings/procest_register.json`, `appinfo/info.xml`,
  `lib/Settings/iv3_taakvelden.json` (new), `lib/Service/Iv3TaakveldList.php`
  (new), `lib/Service/Iv3ReportService.php` (new),
  `lib/Controller/Iv3ReportController.php` (new), `appinfo/routes.php`
  (additive), `src/views/dashboard/Iv3ReportDashboard.vue` (new),
  `src/customComponents.js`, `src/manifest.json`, `src/menu-layout.json`,
  `src/views/settings/tabs/GeneralTab.vue`,
  `src/utils/caseTypeValidation.js` (if a shared options loader is added).
- No new Composer dependencies. No changes to OpenCatalogi, OpenRegister, or
  Pipelinq.
