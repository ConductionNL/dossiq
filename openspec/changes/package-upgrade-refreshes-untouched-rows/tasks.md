# Tasks

- [x] `PackageWriter::writePackage()` reports held rows with the shipped row.
- [x] `ShippedConfigurationService::refreshRows()`; `adopt()` takes the version to stamp.
- [x] `PackageInstaller` refreshes held rows after the ledger record and reports `refreshed` and `kept`.
- [x] Tests through `PackageInstaller::install()`: `testANewerVersionRefreshesRowsNobodyEdited` (fails on the old writer) and `testANewerVersionKeepsARowEditedHereAndNamesIt`.

## Rows the store filled in (OPS-8080, 19 October 2026)

On :8080 every reinstalled row was kept: the ledger stamped the row as the package shipped it, while OpenRegister stores it with every declared property filled, so no stored row ever hashed to its stamp and each read as changed here.

- [x] The ledger stamps a written row as this instance stores it (read back), and records `shippedFingerprint`, the hash of the row as shipped (shippedOrigin 1.1.0, register.d 98).
- [x] A row is untouched when it still hashes to its stamp, or when the keys the package ships still hash to `shippedFingerprint`; a ledger row from before falls back to `fingerprint`, which was the shipped row.
- [x] A refresh writes the shipped keys over the row and keeps every key the package does not ship, so a field filled in here survives; JSON-encoded string properties are encoded again before compare and write (ShippedRowShape).
- [x] Tests: `testANewerVersionRefreshesARowTheStoreFilledIn`, `testALedgerStampedWithTheShippedRowStillRefreshesAnUntouchedRow`, `testARefreshKeepsWhatThisInstanceAddedAndNeverAnEdit` (all three fail without the fix); `testANewerVersionKeepsARowEditedHereAndNamesIt` still holds.
