# Tasks: iv3-taakveld-2023-refinement

## 1. Source verification (REQ-IV3R-001)

- [x] 1.1 Fetch + read the official Rijksoverheid Iv3-Informatievoorschrift 2023 1.0 PDF and the
      "Veelgestelde vragen verfijning Iv3 jeugd en Wmo" FAQ PDF directly (not a secondary
      summary). Document full citation + verbatim excerpts in design.md.

## 2. Data (REQ-IV3R-002, REQ-IV3R-003, REQ-IV3R-004)

- [x] 2.1 `lib/Settings/iv3_taakvelden.json` — bump `version` to `iv3-bbv-v2`, add `geldigVanaf:
      "2023-01-01"`, update `source` citation.
- [x] 2.2 Mark `6.71`/`6.72`/`6.81`/`6.82` `"deprecated": true`.
- [x] 2.3 Add the 18 official 2023-refinement codes (`6.71a-d`, `6.72a-d`, `6.73a-c`, `6.74a-c`,
      `6.81a-b`, `6.82a-b`) each with `aggregatesUnder` pointing at its pre-2023 parent.
- [x] 2.4 Relabel `6.2` → "Toegang en eerstelijnsvoorzieningen", `6.4` → "WSW en beschut werk".

## 3. Iv3TaakveldList (REQ-IV3R-005)

- [x] 3.1 `allTaakvelden()` flattened shape gains `deprecated`/`aggregatesUnder` (extracted into a
      new `flattenTaakveld()` private helper to keep the loop body PHPCS-clean).
- [x] 3.2 `isDeprecated(string $code): bool`.
- [x] 3.3 `aggregationKeyFor(string $code): string`.
- [x] 3.4 `geldigVanaf(): string`.

## 4. Iv3ReportService (REQ-IV3R-006)

- [x] 4.1 `accumulateBuckets()` buckets by `Iv3TaakveldList::aggregationKeyFor($taakveld)` instead
      of the raw code.

## 5. Tests

- [x] 5.1 `Iv3TaakveldListTest` — list-integrity regex allows a trailing letter; deprecated-code
      resolution; refinement-code non-deprecation; `aggregationKeyFor()` for all 18 new codes + a
      deprecated parent + an unaffected code + an unknown code; renamed-label assertions;
      `geldigVanaf()` non-empty.
- [x] 5.2 `Iv3ReportServiceTest` — mixed old+new taakveld codes aggregate into one bucket; two
      different refinement successors of the same parent aggregate together.
- [x] 5.3 Full PHPUnit suite green (CI-equivalent `php:8.3-cli` container, `phpunit-unit.xml`).
- [x] 5.4 PHPCS/PHPMD/Psalm/PHPStan clean on every touched `lib/` file.

## 6. Follow-ups filed, not built here

- [ ] 6.1 No procest UI currently distinguishes deprecated vs. current taakveld codes in the
      `CaseTypeDetail.vue` picker (both appear identically in the dropdown). A follow-up could add
      a visual "deprecated" badge sourced from the `deprecated` field this change now exposes via
      `GET /api/reports/iv3/taakvelden`.
