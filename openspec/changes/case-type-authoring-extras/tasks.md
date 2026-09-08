# Tasks: case-type-authoring-extras

Tier: MVP. Kind: config with two code pieces (D2, D5). Every checkbox is one
implementation task; the criteria under a task are plain bullets.

## 1. Status colour and visibility

- [x] 1.1 `lib/Settings/dossiq_register.json`, schema `statusType`: add
  `colour` (enum, D1) and `hiddenInLists` (boolean, default false).
  - `@spec openspec/specs/case-types/spec.md`
  - `statusType` 1.1.0 -> 1.2.0. OpenRegister fast-skips a schema whose
    version did not move, so the properties would be inert without the bump.
  - The list filter needed a THIRD property the design does not name.
    `status` is a `$ref`, and a filter key `status.hiddenInLists` dot-paths
    into a referenced object: OpenRegister answers no such filter and drops
    it, which is a default filter that silently lists everything. The case
    already carries `isFinalStatus`, a materialised
    `x-openregister-calculations` entry over `@ref.statusType.isFinal`, so
    `hiddenInLists` follows it exactly: `case.statusHiddenInLists`, `case`
    1.17.0 -> 1.18.0. Same caveat as `isFinalStatus`: after editing a
    statusType flag, run `occ openregister:rematerialise-calculations`.
- [x] 1.2 `src/manifest.json`: page `WorkflowBoard` column header and the
  status badge on `CaseDetail` read `colour`; page `Cases` gains the default
  filter `status.hiddenInLists: false`.
  - Only the Cases half is manifest. `WorkflowBoard` is a `type: custom`
    page and the badge on `CaseDetail` is the `case-transitions` custom
    widget, so neither reads a manifest key: the board merges statuses into
    columns in `WorkflowBoard.vue` (`mergeColumnColour`, because two case
    types can colour one status name differently and the merged column can
    only be one of them), and the strip takes `statusColour` off the
    `/available-transitions` answer it already fetches.
  - `CnStatusBadge` was the obvious component and is the wrong one: it takes
    one of six fixed semantic variants, and a colour here is one of twelve
    hue names. Mapping twelve onto six would render two deliberately
    different statuses identically. `StatusBadgeCell.vue` instead, a cell
    widget, because a `formatter` returns a string and a string carries no
    colour.
  - The filter key is `statusHiddenInLists`, not `status.hiddenInLists`
    (see 1.1), and it goes on the `All` chip alone: `All` is the chip marked
    `default`, and `Unclaimed` must stay literally equal to Queue's base
    filter, which a manifest vitest asserts.
- [x] 1.3 `lib/Settings/register.d/*status*.json`: seed colours per role and
  `hiddenInLists: true` on final statuses per design.
  - There is no `*status*.json`: the seeded `statusType` rows live in
    `lib/Settings/dossiq_register.json` (14 rows, each with a `role`) and
    `lib/Settings/register.d/46-demo-cases-english.json` (17 rows, none with
    a role, coloured by name instead). Both carry the colours now.
  - The e2e fixture's own Afgehandeld status (`tests/e2e/helpers/fixtures.ts`)
    deliberately keeps `hiddenInLists` unset, so `case-list-lenses`'s "All
    shows ... the closed one" stays true: closed is not the same claim as
    hidden, and the spec words the filter as hidden.

## 2. A parent type

- [x] 2.1 `lib/Settings/dossiq_register.json`, schema `caseType`: property
  `parentCaseType` (`$ref` caseType). `caseType` 1.2.0 -> 1.3.0.
- [x] 2.2 `lib/Service/CaseTypeResolver.php` (new) per design D2, with a
  cycle refusal and unit tests in `tests/Unit/Service/CaseTypeResolverTest.php`.
  - It arrived as ONE class and phpmd refused it: overall complexity 73
    against a threshold of 50. Split the way `CaseStatusStore` was split out
    of `StatusTransitionService` — `lib/Service/CaseTypeStore.php` owns every
    OpenRegister read (and writes nothing), the resolver owns the merge, the
    chain and the cycle refusal.
  - The merge key is the row's NAME, lower-cased and trimmed. It cannot be
    the id: ids are minted per install, so a child has no way to name the
    parent row it means to override.
  - Only `null`, `''` and `[]` count as "the child said nothing". An explicit
    `false` is an answer, and inheriting it would turn every child of a type
    that allows suspension into one that allows it too.
  - `@spec openspec/specs/case-types/spec.md`
- [ ] 2.3 Move every reader of `statusType`, `resultType` and
  `propertyDefinition` by `caseType` to the resolver; list them first with
  `grep -rn "'caseType'" lib/Service` and record the list here.
- [ ] 2.4 `src/manifest.json` page `CaseTypeDetail`: widget
  `case-type-parent` and the Inherited badge per design D2.

## 3. Folders and shared attributes

- [ ] 3.1 `lib/Settings/dossiq_register.json`: `caseType.category` (string,
  facet); `propertyDefinition.caseType` out of `required`.
  - `@spec openspec/specs/property-definition-management/spec.md`
- [ ] 3.2 `src/manifest.json` page `CaseTypes`: `folderSidebar` on
  `category`; page `CaseTypeDetail`: the Properties tab lists own rows and
  shared rows per design D3.

## 4. The AVG block

- [ ] 4.1 `lib/Settings/dossiq_register.json`, schema `caseType`:
  `processesPersonalData`, `personalDataCategories`, `legalBasis`,
  `verwerkingsactiviteit` per design D4.
  - `@spec openspec/specs/avg-verwerkingenlogging/spec.md`
- [ ] 4.2 `src/manifest.json` page `CaseTypeDetail`: widget
  `case-type-privacy`.
- [ ] 4.3 [blocked: openregister the verwerkingsregister as a referenceable
  schema] `verwerkingsactiviteit` becomes a `$ref`; until then a string
  with a datalist of seeded codes.

## 5. Export, import, duplicate, publish

- [ ] 5.1 `lib/Controller/CaseDefinitionController.php`: method `publish`
  per design D5, routed in `appinfo/routes.php` with `#[NoAdminRequired]`
  and an admin guard in the body; unit test in
  `tests/Unit/Controller/CaseDefinitionControllerTest.php`.
  - `@spec openspec/specs/zaaktype-versioning/spec.md`
- [ ] 5.2 `src/manifest.json` page `CaseTypeDetail`: the four header
  actions and the widget `case-type-versions` per design D5.
  - verify `run-action` handles a download; else `export` becomes a
    `handler` opening the URL, and record it here
- [ ] 5.3 `l10n/en.json` and `l10n/nl.json`: Colour, Hidden in lists,
  Parent case type, Inherited, Category, Shared attributes, Personal data,
  Export, Import, Duplicate, Publish, Change note, Versions.

## 6. Verification

- [ ] 6.1 Add `tests/e2e/case-type-authoring-extras.spec.ts` covering every
  scenario of the deltas that names it.
- [ ] 6.2 Run `composer check:strict`, `npm run check:manifest`, the hydra
  gates and the unit suite locally; read the exit codes, not the summaries.
