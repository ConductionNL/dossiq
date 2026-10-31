# Tasks: doorlooptijd-csv

### Task 1: The processing-time figures download as CSV
- **spec_ref**: `openspec/changes/doorlooptijd-csv/specs/doorlooptijd-csv/spec.md#requirement-the-processing-time-figures-download-as-csv`
- **files**: `lib/Controller/DoorlooptijdController.php`, `lib/Service/DoorlooptijdService.php`, `lib/Service/Doorlooptijd/CaseTypeThroughputCalculator.php`, `lib/Service/Csv/CsvWriter.php`, `lib/Woo/WooThroughputReport.php`, `src/manifest.json`
- [x] Implement: `format=csv`, monthly rows per case type, the shared CSV writer, the header action
- [x] Test: `tests/Unit/Controller/DoorlooptijdControllerContractTest.php`, `tests/Unit/Service/Doorlooptijd/MonthlyByCaseTypeTest.php`, `tests/vitest/doorlooptijdDownload.spec.js`
