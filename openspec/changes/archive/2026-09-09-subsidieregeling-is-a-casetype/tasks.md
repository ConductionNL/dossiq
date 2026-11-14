# Tasks: subsidieregeling-is-a-casetype

Written retroactively on 2026-09-09. The work shipped on 2026-08-27 in
`05919d25`, but the change directory was only ever a proposal: no tasks, no spec
delta. That is why it sat in `openspec/changes/` for two weeks after it was
finished. Every box below was verified against `development` before it was
ticked, and the evidence is on the box.

## 1. The schema

- [x] 1.1 Re-point `subsidieAanvraag.subsidyScheme` from `subsidieRegeling` to
  `caseType`, keeping the uuid shape.
  - `lib/Settings/register.d/50-subsidie.json:40` carries
    `"$ref": "caseType"` and names this change in its description.
- [x] 1.2 Add `enum` and `json` to `propertyDefinition.propertyType`.
  - both are in the enum in `lib/Settings/dossiq_register.json`, with the
    description recording why flattening them to `string` was refused.
- [x] 1.3 Mark `subsidieRegeling` deprecated and keep it for one release rather
  than deleting it, so the migration can still resolve and read the rows it
  converts.
  - `lib/Settings/register.d/50-subsidie.json` carries the DEPRECATED
    description and the reason: a schema the register no longer carries returns
    zero rows, and a migration that reads nothing reports success.

## 2. The migration

- [x] 2.1 Write `MigrateSubsidieRegelingToCaseType`: map the four direct fields,
  convert `requestTermWeeks` to an ISO-8601 duration, and turn the remaining
  grant-specific properties into `propertyDefinition` records.
  - `lib/Repair/MigrateSubsidieRegelingToCaseType.php`.
- [x] 2.2 Make it report counts rather than success, and handle an empty set
  gracefully.
  - the class docblock records the measurement it was written against, 2
    `subsidieRegeling` objects and 0 `subsidieAanvraag` rows referencing them,
    and says an instance with two thousand rows is a different change.
- [x] 2.3 Unit-test the migration.
  - `tests/Unit/Repair/MigrateSubsidieRegelingToCaseTypeTest.php`.

## 3. The surface

- [x] 3.1 Remove `/subsidieregelingen` and its Subsidy schemes menu entry;
  schemes are administered on the Case types index.
  - `git grep subsidieregelingen -- src` returns nothing.
- [x] 3.2 Leave the retired route falling through rather than erroring.
  - asserted in `tests/e2e/spec-coverage/subsidieregeling-is-a-casetype.spec.ts`
    on the page's own create control, because a retired route falls through to
    the app root and the root has headings of its own.

## 4. Coverage

- [x] 4.1 Cover the migration and the retired surface end to end.
  - `tests/e2e/spec-coverage/subsidieregeling-is-a-casetype.spec.ts`, six tests
    across two describes.
- [x] 4.2 Write the spec delta this change never had, against `case-types`.
  - `specs/case-types/spec.md`, REQ-CT-21 to REQ-CT-23. `case-types` rather than
    `subsidieverlening-keten` because every requirement here is about how a
    blueprint is modelled and administered, not about the grant chain's
    lifecycle: the four field mappings, the `propertyType` extension, the
    `propertyDefinition` records and the index the schemes moved onto are all
    case-type machinery. Nothing here touches aanvraag, beoordeling, beschikking
    or vaststelling.

## 5. Left for the follow-up

- [ ] 5.1 Delete the `subsidieRegeling` schema once the migration has run on
  every instance. Deliberately not done here: 1.3 keeps it for one release on
  purpose, and deleting it in the same change would leave the migration reading
  zero rows and reporting success.
