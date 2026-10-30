# Case costs reported per Iv3 taakveld

## Why

Row 10.20 (report case costs per Iv3 taakveld, with 2023 refinement codes rolled up to their parent). The list and the roll-up exist: `lib/Service/Iv3TaakveldList.php` holds the taakvelden with `aggregationKeyFor()`, `GET /api/reports/iv3/taakvelden` serves them and a picker sets a taakveld on the case. The spec's third requirement, the quarterly aggregation (`Iv3ReportService`), has no class, and no page or export reports costs per taakveld. No dossiq board draws the reports page yet; this change proposes DqRapportages for a board lane (gap list b).

## What changes

- A generic grouped-cost report: sum a case's cost lines per value of a case field over a period, with an optional key map that rolls values up (decision 182: named for what it does, the Iv3 roll-up is its configuration).
- The Iv3 report is that report grouped on the case's taakveld with `aggregationKeyFor()` as the key map, per quarter, on the reports page and as CSV.

## Impact

- `lib/Service/Reporting/GroupedCostReport.php`, a route `GET /api/reports/grouped-costs` (field, period, keyMap), `src/manifest.json` page `Reports` widget and CSV export.
- Spec: a requirement added to `iv3-taakveld-2023-refinement` that names the generic report as the Iv3ReportService of the spec.
