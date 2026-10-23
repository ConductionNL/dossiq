# Tasks: record-import-moves-in-app-records

- [x] 1.1 `RecordCaseMapping` reads `from` as a dot path (test fails on development)
- [x] 1.2 `as` boolean, number, json
- [x] 1.3 `property` targets append `{propertyDefinition, name, value}` to the list at `to`
- [x] 1.4 `statuses["*"]` fallback
- [x] 2.1 `CaseRecordImport` skips the term carry-over when `carriesTerm` is false
- [x] 2.2 `CaseRecordImport::runOnUpgrade()` runs every flagged declaration of every case type in use
- [x] 2.3 Repair step `RunUpgradeRecordImports`, registered after `InstallShippedPackages`
- [x] 3.1 caseType 1.35.0 declares `carriesTerm` and `runOnUpgrade`
