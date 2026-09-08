# Tasks: parties-on-the-case

Tier: V1. Kind: config. Every checkbox below is one implementation task; the
criteria under a task are plain bullets.

## 1. The Parties tab

- [x] 1.1 `src/manifest.json` page `CaseDetail`: add widget `case-roles`
  (`type: object-list`, `register: dossiq`, `schema: role`, `filter.case:
  @objectId`, sort `roleType` asc, limit 50, columns `roleType`,
  `participant`, `delegate`, `delegateUntil`, `emptyText`); add it to
  `case-panels.content.tabs` as Parties before Contacts; keep it out of
  `layout`.
  - `@spec openspec/specs/roles-decisions/spec.md`
- [x] 1.2 `src/manifest.json` page `CaseDetail`: remove the `case-contacts`
  widget and its tab entry; grep `tests/e2e` for the Contacts label first and
  update `tests/e2e/case-detail-kpis-and-tabs.spec.ts`.
  - `npm run check:manifest` exits 0

## 2. Add party

- [x] 2.1 `src/manifest.json` page `CaseDetail`: header action `add-party`
  (`type: open-form`, `register: dossiq`, `schema: role`, `props.case:
  @objectId`, label Add party, icon `AccountPlusOutline`, `successMessage`).
  - the form's `roleType` select lists only the case type's role types — NOT
    met, and deliberately not faked: reaching `roleType.caseType` from the
    role form means hopping through `role.case`, and CnFormDialog resolves an
    `x-relation-filter` only against the form's own flat fields, dropping an
    unresolved entry instead of applying it. A declared filter would read as
    the restriction while listing every role type on the instance. Guarded by
    a vitest that fails if one is added without the platform hop.
  - the form asks for `name` as well: `role` requires it, and an `open-form`
    action saves straight to the object API, so a required property that is
    neither asked nor seeded 400s every save
  - `@spec openspec/specs/roles-decisions/spec.md`
- [ ] 2.2 [blocked: nextcloud-vue `CnObjectListWidget` opening its create
  form with the list's filter as initial data (triage #6, Tier D05)] Move Add
  onto the Parties tab through `showAdd` and drop the header action; 2.1 is
  the interim.

## 3. A team on the case and the task

- [x] 3.1 `lib/Settings/dossiq_register.json` and
  `lib/Settings/dossiq_mock_register.json`: property `assignedGroup` on
  `case` and `assigneeGroup` on `caseTask`, both `string`, `format: uuid`,
  `$ref: organisatieRol`, title Team, `facetable: true`, optional. Bump the
  two schema versions.
  - unit test in `tests/Unit/Settings/RegisterSchemaGroupFieldsTest.php`:
    both properties present, optional, `$ref` to `organisatieRol`, and the
    mock register carries the same two
  - `@spec openspec/specs/role-routing-via-or-rbac/spec.md`
- [x] 3.2 `lib/Settings/register.d/61-mandaat-matrix.json`: seed two
  `organisatieRol` rows (Team Vergunningen and Team Handhaving, department
  Ruimte); `lib/Settings/register.d/46-demo-cases-english.json`: set
  `assignedGroup` on two demo cases.
  - unit test in `tests/Unit/Settings/MandaatMatrixSeedTest.php`: the two
    rows exist with `roleName` and `department`
- [x] 3.3 `src/manifest.json` pages `Cases` and `Tasks`: a Team column
  (`assignedGroup` and `assigneeGroup`) after `assignee`; `quickFilters`
  with Mine (`assignee: @me`).
  - the column key is `<property>.roleName` with the property in `extend`:
    a bare $ref column renders a uuid in every row, the way the Tasks index
    already learned with `case.title`
  - an All chip marked `default` ships beside Mine. CnIndexPage activates the
    first chip (or the one marked default) on mount, so Mine alone would open
    both indexes filtered to the signed-in handler's own work
  - `npm run check:manifest` exits 0
- [ ] 3.4 [blocked: nextcloud-vue a `quickFilters` value that resolves to the
  signed-in user's `organisatieRol` ids from `medewerkerRolToewijzing`] Add
  the Team chip beside Mine on `Cases` and `Tasks`; the facet on the group
  field from 3.1 is the interim.

## 4. Verification

- [x] 4.1 Add `tests/e2e/case-parties.spec.ts` covering every scenario of
  the two delta specs that names it: the Parties tab with seeded roles, the
  empty tab, the delegate columns, Add party with the case prefilled and the
  role type restricted, the saved role in the tab, the Team field on the case
  and task forms, the Team column and facet on both indexes, the Mine chip.
  Seed through `seedCase`, `ensureCaseType` and `createObject` from
  `tests/e2e/helpers/fixtures.ts`; clean up with `cleanupRunObjects`.
- [x] 4.2 Update `tests/e2e/case-detail-kpis-and-tabs.spec.ts`: the tab
  strip holds Parties and no Contacts.
- [ ] 4.3 Run `composer check:strict`, `npm run check:manifest`, the hydra
  gates and the unit suite locally; read the exit codes, not the summaries.
