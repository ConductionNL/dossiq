# Proposal: record-import-moves-in-app-records

## Why

Ruben (23 Oct 2026): re-integration trajectories should be a case type. Procedures are case types,
flows and configuration; code is generic. dossiq already has one generic way to turn stored
records into cases of a type, `caseType.recordImports` (case-record-import, built for the Woo
take-over from opencatalogi). It was shaped by that one source and cannot yet move a record that
lives in dossiq's own register:

- it reads only top-level source fields, so a nested value (`exemptionWorkObligation.actief`) is unreachable;
- it reads only text, dates, moments and integers, so a boolean, a number or a list is dropped;
- it writes only case fields, while most of a procedure's data belongs in the case type's own fields (`case.properties[]`);
- it refuses a record without a status, and a record kept as a plain list has none;
- it always carries a statutory term, and refuses the record when the case type has none;
- it runs only from `occ`, so an upgrade cannot move the records before the old page goes.

## What changes

All in the generic importer, configured per case type:

- `fields[].from` reads a dot path on the source record.
- `fields[].as` also takes `boolean`, `number` and `json` (a list or object kept as JSON text).
- `fields[].property` `{definition, name}` makes the field an answer to one of the case type's fields: it is appended to the list at `to` (`properties`) as `{propertyDefinition, name, value}`, the value as text like every answer.
- `statuses["*"]` is the status of a record whose status is not declared or absent.
- `carriesTerm: false` on a declaration skips the term carry-over for a case type without a statutory term.
- `runOnUpgrade: true` on a declaration makes the new repair step `RunUpgradeRecordImports` run it on every upgrade, after the shipped packages are installed. It is idempotent like every run, and reports its counts.
- caseType schema 1.35.0 declares `carriesTerm` and `runOnUpgrade`.

## Impact

- `lib/Service/Import/RecordCaseMapping.php`, `CaseRecordImport.php`, new `lib/Repair/RunUpgradeRecordImports.php`, `appinfo/info.xml`, `lib/Settings/register.d/99-case-type-record-import-case-fields.json`.
- The Woo declaration is unchanged and maps as before.
