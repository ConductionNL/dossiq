# Tasks: iv3-case-cost-reporting

## 1. Taakveld reference list
- [x] 1.1 `lib/Settings/iv3_taakvelden.json` — versioned BBV taakveld list (9
      categories, ~55 subcodes), sourced per design.md.
- [x] 1.2 `lib/Service/Iv3TaakveldList.php` — `allTaakvelden()`,
      `isValidCode()`, `labelFor()`, `version()`.
- [x] 1.3 Unit test: list integrity (well-formed codes, no duplicates, known
      code labels, unknown-code rejection).

## 2. Schema changes
- [x] 2.1 `caseType.iv3Taakveld` — optional string property.
- [x] 2.2 `case.kosten` — optional JSON-encoded-array string property.
- [x] 2.3 Bump `caseType` schema version `1.1.0` → `1.2.0`, `case` schema
      version `1.7.0` → `1.8.0`.
- [x] 2.4 Bump `procest_register.json` `info.version` and
      `appinfo/info.xml` `<version>` per the established re-import
      convention.

## 3. Aggregation service
- [x] 3.1 `lib/Service/Iv3ReportService.php` —
      `generateQuarterlyReport(int $year, int $quarter): array` per
      design.md semantics.
- [x] 3.2 `Iv3ReportService::asCsv(array $report): string`.
- [x] 3.3 Unit tests: single case/single taakveld; multiple taakvelden kept
      separate; quarter-boundary exactness; uncategorized bucket; empty
      quarter; case with no activity this quarter excluded; CSV shape
      (header + rows, uncategorized row).

## 4. Controller + routes
- [x] 4.1 `lib/Controller/Iv3ReportController.php` —
      `report(int $year, int $quarter, string $format='json')` and
      `taakvelden()`.
- [x] 4.2 `report()` gated via `IGroupManager` allow-list
      (`controllers`/`beheerders`/`admin`) + `isAdmin()` fallback, mirroring
      `AiAuditExportController`.
- [x] 4.3 `taakvelden()` open to any authenticated user.
- [x] 4.4 Routes: `reports#iv3` `GET /api/reports/iv3`,
      `reports#iv3Taakvelden` `GET /api/reports/iv3/taakvelden`.
- [x] 4.5 Unit tests: RBAC (allowed group / admin fallback / denied plain
      user / unauthenticated), JSON vs CSV `format=`, taakvelden endpoint
      open access.

## 5. Frontend
- [x] 5.1 `src/views/dashboard/Iv3ReportDashboard.vue` — year/quarter
      picker, taakveld table, CSV download button (structural copy of
      `TermijnDashboard.vue`'s report section).
- [x] 5.2 Register in `src/customComponents.js`, `src/manifest.json`
      (`type: "custom"` page), `src/menu-layout.json` (nav entry inside
      `AnalyticsGroup`).
- [x] 5.3 `src/views/settings/tabs/GeneralTab.vue` — `iv3Taakveld` `NcSelect`
      picker, options fetched from `GET /api/reports/iv3/taakvelden`.
- [x] 5.4 `CaseTypeDetail.vue` `EMPTY_FORM` — add `iv3Taakveld: ''` default.
- [x] 5.5 i18n: every new `t('procest', '...')` string has an EN source
      string and an NL translation pair.

## 6. Verification
- [x] 6.1 `openspec validate iv3-case-cost-reporting --type change --strict`
      passes.
- [x] 6.2 Full PHPUnit suite green (CI-equivalent `php:8.3-cli` container,
      `phpunit-unit.xml`) — not just the new tests.
- [x] 6.3 Full vitest suite green.
- [x] 6.4 `npm run build` exits 0.
- [x] 6.5 Archive the change under
      `openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/`.

## Follow-ups (out of scope for this change, per design.md)
- Populate `case.kosten` automatically from `subsidieVaststelling.vastgesteldBedrag`
  when a subsidy case is settled, instead of relying on manual entry.
- Add the CBS 2023 Wmo/Jeugd taakveld-6 refinement subcodes
  (`6.711`/`6.712`/… replacing `6.71`/`6.72`) as an additive list update.
- A Pipelinq-transaction-backed leges income source (mirrors
  `OpenCatalogiApiClient`), replacing manual `leges_income` entry.
