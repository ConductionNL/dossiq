# Tasks: case-objects-hinge-on-the-object

Tier: V1. Kind: code. Size S. Parity ledger row 2.15, cluster 5. The
consumer half of openregister `objects-as-the-hinge-between-cases`
(openregister#3765, `2f271e1fa`), which is merged: every mechanism below
is already on openregister `development` and every task here is a
declaration or a cell.

- [x] 1.1 `caseObject`: declare `x-openregister-lenses` for the linked
  object's title and status, through `objectUrl`, and bump the schema
  version so the importer does not fast-skip it (D-1).
  - `tests/vitest/caseObjectHinge.spec.js`
  - `@spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md`
- [x] 1.2 The Objects tab shows both lenses, through the `lensedValue`
  cell, and every lens column names a lens the schema declares (D-1).
  - `tests/vitest/caseObjectHinge.spec.js`
- [x] 2.1 `src/utils/lensValue.js`: read the withheld marker, and drop the
  lens properties from a record before writing it back (D-2).
  - `tests/vitest/lensValue.spec.js`
- [x] 2.2 `LensedValueCell`: three states, three shapes. Withheld in
  words with the reason on hover, an em dash for nothing, the value
  otherwise (D-2).
  - `tests/vitest/lensedValueCell.spec.js`
- [x] 3.1 `caseObject`: declare `x-openregister-list`, every column and
  search field naming a property the schema declares, and keep the
  Objects index showing the declared columns in the declared order (D-1).
  - `tests/vitest/caseObjectHinge.spec.js`
- [x] 4.1 `caseObject`: `objectNameField` onto a materialised `caseTitle`
  calculation reading `@ref.case.title`, coalescing to the identification
  and then the object type (D-3).
  - `tests/vitest/caseObjectHinge.spec.js`
- [x] 4.2 Verify openregister's Referenced by tab on the linked object's
  page rather than rebuilding it. Recorded in the proposal: the group
  lists `caseObject` link rows, and naming each row after its case is
  what makes the list readable (D-3).
- [x] 5.1 `case-location`: declare `linkedObject` and
  `x-openregister-geo-inheritance` through it, and bump the schema
  version (D-4).
  - `tests/vitest/caseObjectHinge.spec.js`
- [x] 6.1 `lib/Settings/intake_sources.json`: the five channels, each
  with a slug, a title, a description and its transport (D-5).
  - `tests/Unit/Service/Intake/IntakeSourceSeederTest.php`
- [x] 6.2 `IntakeSourceSeeder`: upsert on slug, create switched off,
  refresh only dossiq's own wording, and report rather than throw where
  the register is absent (D-5).
  - `tests/Unit/Service/Intake/IntakeSourceSeederTest.php`
- [x] 6.3 `SeedIntakeSources` in both repair blocks, after the register
  import (D-5).
- [x] 7.1 English and Dutch for the new labels and the withheld sentence.
- [x] 7.2 `tests/e2e/case-objects-hinge.spec.ts`, tagged against the
  scenarios above. Written, not run: this lane has no Playwright host.

## Verified

- `bash diff-check.sh --base origin/development`, NEW findings only.
- `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, once.
- `npm run lint`, `npm run test:l10n`, `npx vitest run`, once each.
