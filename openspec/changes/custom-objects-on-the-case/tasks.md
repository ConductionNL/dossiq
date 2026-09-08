# Tasks: custom-objects-on-the-case

Tier: MVP. Kind: config. Every checkbox below is one implementation task; the
criteria under a task are plain bullets.

## 1. The facet on the object type

- [ ] 1.1 `lib/Settings/dossiq_register.json`, schema `caseObject`:
  `x-openregister-facet: true` on `objectType` per design D4 and bump
  `version` to 1.1.0.
  - `@spec openspec/specs/case-management/spec.md`
  - `tests/schemas` round-trips the annotation

## 2. The Objects tab

- [ ] 2.1 `src/manifest.json` page `CaseDetail`: widget `case-objects` per
  design D1 and its tab entry Objects in `case-panels` after Locations,
  without a `layout` cell.
  - `@spec openspec/specs/case-management/spec.md`
  - `npm run check:manifest` exits 0
  - `tests/unit/manifest.spec.ts` (extend): `case-objects` filters on
    `case: "@objectId"` and is not in `layout`

## 3. Link object

- [ ] 3.1 `src/manifest.json` page `CaseDetail`: header action `link-object`
  per design D2 with `props: {case: "@objectId"}` and `includeFields`
  `objectType`, `objectIdentification`, `objectUrl`, `description`.
  - `@spec openspec/specs/case-management/spec.md`
- [ ] 3.2 [blocked: nextcloud-vue `CnObjectListWidget` and `CnDetailPage`
  passing a filter or `props` into the create form as initial data (triage
  #6, Tier D05)] Interim: 3.1 passes the case in `props`; the e2e asserts
  the saved object's `case`, not the prefilled field. When the change lands,
  drop the interim note and enable the prefill scenario.
- [ ] 3.3 `l10n/en.json` and `l10n/nl.json`: Objects, Link object, Object
  type, Identification, Link, "No objects linked to this case yet", "Object
  linked to this case.", View case, All objects.

## 4. The Objects index

- [ ] 4.1 `src/manifest.json`: page `CaseObjects` per design D3 with the
  facet folder sidebar on `objectType`, the four columns and the View case
  row action navigating to `CaseDetail` on the row's `case`; menu entry
  Objects in the Cases group after All cases.
  - `@spec openspec/specs/case-management/spec.md`
  - `npm run check:manifest` exits 0
  - `tests/unit/manifest.spec.ts` (extend): `CaseObjects` reads schema
    `caseObject` and its sidebar facet is `objectType`
- [ ] 4.2 `src/formatters` (or wherever `caseTypeName` is registered):
  `caseTitle` resolving a case id to its title, used by the `case` column.
  Skip when the index already renders `$ref` columns by label (triage #8).
  - `tests/unit/formatters.spec.ts` (new if absent): an id resolves to the
    title; an unknown id falls back to the id

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
