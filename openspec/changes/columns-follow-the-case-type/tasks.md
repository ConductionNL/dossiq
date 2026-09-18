# Tasks: columns-follow-the-case-type

Tier: V1. Kind: config. Row 11.9.

- [ ] 1.1 Raise `@conduction/nextcloud-vue` in `package.json` to the version
  carrying nextcloud-vue PR 1213, and confirm `CnIndexPage` resolves scope
  columns in the built bundle rather than only in the library source.
  - `@spec openspec/changes/columns-follow-the-case-type/specs/case-management/spec.md`
- [ ] 2.1 `src/manifest.json` `#Cases`: declare `columns` per `caseType` scope
  on the `folderSidebar`, and leave the All types folder on the page columns.
- [ ] 2.2 `#Cases`: declare `sort` per scope where the type has a date that
  orders it better than `createdAt`.
- [ ] 2.3 `#Tasks`: the same, scoped to the case type of the task's case.
- [ ] 3.1 Seeded case types declare their columns: omgevingsvergunning shows
  the decision date and the expiry, bezwaar shows the contested decision and
  the hearing date, klacht shows the channel and the receipt date.
- [ ] 4.1 `tests/vitest/`: every scope column names a property its case type
  carries, and a scope without `columns` inherits the page's.
- [ ] 4.2 `tests/e2e/columns-follow-the-case-type.spec.ts`: pick a type in the
  sidebar, the header row changes; pick All types, it changes back.
