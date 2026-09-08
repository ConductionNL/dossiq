# Tasks: case-type-authoring-extras

Tier: MVP. Kind: config with two code pieces (D2, D5). Every checkbox is one
implementation task; the criteria under a task are plain bullets.

## 1. Status colour and visibility

- [ ] 1.1 `lib/Settings/dossiq_register.json`, schema `statusType`: add
  `colour` (enum, D1) and `hiddenInLists` (boolean, default false).
  - `@spec openspec/specs/case-types/spec.md`
- [ ] 1.2 `src/manifest.json`: page `WorkflowBoard` column header and the
  status badge on `CaseDetail` read `colour`; page `Cases` gains the default
  filter `status.hiddenInLists: false`.
- [ ] 1.3 `lib/Settings/register.d/*status*.json`: seed colours per role and
  `hiddenInLists: true` on final statuses per design.

## 2. A parent type

- [ ] 2.1 `lib/Settings/dossiq_register.json`, schema `caseType`: property
  `parentCaseType` (`$ref` caseType).
- [ ] 2.2 `lib/Service/CaseTypeResolver.php` (new) per design D2, with a
  cycle refusal and unit tests in `tests/Unit/Service/CaseTypeResolverTest.php`.
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
