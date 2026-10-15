# Tasks

- [x] `PackageWriter::writePackage()` reports held rows with the shipped row.
- [x] `ShippedConfigurationService::refreshRows()`; `adopt()` takes the version to stamp.
- [x] `PackageInstaller` refreshes held rows after the ledger record and reports `refreshed` and `kept`.
- [x] Tests through `PackageInstaller::install()`: `testANewerVersionRefreshesRowsNobodyEdited` (fails on the old writer) and `testANewerVersionKeepsARowEditedHereAndNamesIt`.
