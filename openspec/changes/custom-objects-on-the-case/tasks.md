# Tasks: custom-objects-on-the-case

Tier: MVP. Kind: config. Every checkbox below is one implementation task; the
criteria under a task are plain bullets.

## 1. The facet on the object type

- [x] 1.1 `lib/Settings/dossiq_register.json`, schema `caseObject`:
  `facetable: true` on `objectType` (design D4 spells it
  `x-openregister-facet`, which nothing reads: the index sidebar builds its
  filters from the schema property's `facetable` flag alone) and bump
  `version` to 1.1.0.
  - `@spec openspec/specs/case-management/spec.md`
  - `tests/vitest/caseObjects.spec.js` round-trips the annotation and the
    version (the repo has no schema round-trip suite under `tests/schemas`,
    which holds only the manifest JSON schema)

## 2. The Objects tab

- [x] 2.1 `src/manifest.json` page `CaseDetail`: widget `case-objects` per
  design D1 and its tab entry Objects in `case-panels` after Locations,
  without a `layout` cell.
  - `@spec openspec/specs/case-management/spec.md`
  - `npm run check:manifest` exits 0
  - `tests/vitest/caseObjects.spec.js` (unit specs live in `tests/vitest/`,
    not `tests/unit/`): `case-objects` filters on `case: "@objectId"`, is an
    `object-list` and is not in `layout`

## 3. Link object

- [x] 3.1 `src/manifest.json` page `CaseDetail`: header action `link-object`
  per design D2 with `props: {case: "@objectId"}` and `includeFields`
  `objectType`, `objectIdentification`, `objectUrl`, `description`.
  - `@spec openspec/specs/case-management/spec.md`
- [ ] 3.2 [blocked: nextcloud-vue `CnObjectListWidget` and `CnDetailPage`
  passing a filter or `props` into the create form as initial data (triage
  #6, Tier D05)] Interim: 3.1 passes the case in `props`; the e2e asserts
  the saved object's `case`, not the prefilled field. When the change lands,
  drop the interim note and enable the prefill scenario.
- [x] 3.3 `l10n/en.json` and `l10n/nl.json`: Objects, Link object, Object
  type, Identification, "No objects linked to this case yet", "Object
  linked to this case.", All objects. Link and View case were already in
  both catalogues. `l10n/en.js` and `l10n/nl.js` are rebuilt with
  `npm run l10n:build`, or `check:l10n-js` calls the catalogue stale.

## 4. The Objects index

- [x] 4.1 `src/manifest.json`: page `CaseObjects` per design D3 with the
  folder sidebar on `objectType`, the four columns and the View case row
  action navigating to `CaseDetail` on the row's `case`; menu entry Objects
  after All cases. The sidebar is `source: "field"`, not the design's
  `source: "facet"`: CnFolderSidebar takes `custom`, `field` or `files` and
  rejects anything else in a prop validator, so `facet` renders no sidebar
  and says so only in the console.
  - `@spec openspec/specs/case-management/spec.md`
  - `npm run check:manifest` exits 0
  - `tests/vitest/caseObjects.spec.js`: `CaseObjects` reads schema
    `caseObject`, its sidebar groups on `objectType`, and that property is
    `facetable`
- [x] 4.2 `src/services/formatters.js`, beside `caseTypeName`: `caseTitle`
  resolving a case id to its title, used by the `case` column. NOT skipped:
  the sibling Tasks index renders its `$ref` column by label through
  `extend`, and `extend` replaces `row.case` with the expanded object, which
  would leave the View case action pushing an object instead of a uuid and
  opening nothing. The formatter renders the label and leaves the row alone.
  - `tests/vitest/formatters.spec.js` (new): an id resolves to the title; an
    unknown id falls back to the id; a missing reference shows a dash

## 5. Seed and verification

- [ ] 5.1 `lib/Settings/register.d/46-demo-cases-english.json`: three case
  objects per design Seed Data, one building shared by two cases and one
  vehicle, each with `case` set.
- [ ] 5.2 Add `tests/e2e/case-objects.spec.ts` covering every scenario of
  the delta spec that names it: the tab listing two rows and not the other
  case's, the empty state, a linked object showing up with `case` set, the
  refused save without an object type, the index finding one building on
  two cases, View case opening the case, and the sidebar grouping. Assert on
  ids and saved objects, not English labels.
- [ ] 5.3 Run `composer check:strict`, `npm run check:manifest`, the hydra
  gates and the unit suite locally; read the exit codes, not the summaries.
