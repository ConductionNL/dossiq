# Tasks: package-adoption-fills-missing-case-type-fields

- [x] 1.1 `lib/Service/CaseDefinition/AdoptedCaseTypeFill.php`: the shipped fields a stored row lacks.
- [x] 1.2 `PackageWriter::adopt()` reports the fill for a case type; `writePackage()` points it at adopted ids; `fillAdopted()` writes it.
- [x] 1.3 `PackageInstaller::write()` fills after the ledger record; the outcome adds `filled`.
- [x] 1.4 `tests/Unit/Service/CaseDefinition/PackageInstallerTest.php`: adoption fills only the lacked fields, keeps the stored ones, and a failed install fills nothing.
- [x] 1.5 `lib/Settings/packages/wmo-melding/package.json` 1.1.0 to 1.1.1, so an instance that adopted 1.1.0 gets `recordImports` on upgrade.
