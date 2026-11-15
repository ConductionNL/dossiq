# Tasks: procesanalyse-csv

### Task 1: The days per phase download as CSV
- **spec_ref**: `openspec/changes/procesanalyse-csv/specs/procesanalyse-csv/spec.md#requirement-the-days-per-phase-download-as-csv`
- **files**: `lib/Controller/ProcessMiningController.php`, `src/manifest.json`
- [x] Implement: `format=csv` and `statistic`, the header action
- [x] Test: `tests/Unit/Controller/ProcessMiningCsvTest.php`, `tests/vitest/procesanalyseDownload.spec.js`
