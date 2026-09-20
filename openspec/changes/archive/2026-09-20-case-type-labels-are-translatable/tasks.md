# Tasks: case-type-labels-are-translatable

Tier: V1. Kind: config. Row 11.13.

- [x] 1.0 Rename every `x-translatable` to `translatable` in
  `lib/Settings/dossiq_register.json` (24), `dossiq_mock_register.json` (21),
  `register.d/30-beschikking.json` (1) and `register.d/48-starter-content.json`
  (4). Read the diff, not the match count: the prefix is a substring of the
  bare key and a careless replace writes `x-x-translatable`.
  - `@spec openspec/specs/case-configuration-i18n/spec.md`
  - Landed in #2988. Fifty keys, counted per file as above, and zero
    `x-translatable` left under `lib/Settings/`. Two of the fifty are
    `translatable: false`, which is a declaration and not an omission.
- [x] 1.1 Mark `translatable: true` with `sourceLanguage: "nl"` on
  `caseType.title` and `caseType.description` in
  `lib/Settings/dossiq_register.json`, if the rename did not already.
- [x] 1.2 The same on `statusType.name` and `description`, `resultType.title`
  and `description`, `documentType.title`, `roleType.title` and
  `transition.label`.
  - Done for all forty-five top-level translatable properties across
    `dossiq_register.json` (21), `dossiq_mock_register.json` (20) and
    `register.d/48-starter-content.json` (4), not for a list somebody keeps.
    `TranslatableLabelsTest::testEveryTranslatableLabelDeclaresItsSourceLanguage`
    walks the files, so a property added later is covered the day it is added.
- [x] 1.3 Confirm against a running instance that OpenRegister accepts the
  pair: `sourceLanguage` without `translatable: true` is refused with
  `error.reason: 'sourceLanguage requires translatable: true'`, so a typo
  fails the import rather than passing silently.
  - THE PREMISE IS WRONG AND THE TASK CANNOT BE DONE AS WRITTEN. No such
    refusal exists anywhere in openregister on `development`: the string
    `sourceLanguage requires` appears in no PHP file. `resolveSourceLanguage()`
    is only ever called for a property already in
    `getTranslatableProperties()`, so a `sourceLanguage` on an unmarked
    property is read by nobody and dropped in silence, which is the same
    failure mode `x-translatable` had.
  - What replaces it: the test asserts the PAIR, in both directions. A
    `translatable` without a `sourceLanguage` fails, because the engine would
    fall back to the register default and nothing would record which side was
    the original.
- [x] 2.1 The register declares its languages. Set `languages` on the dossiq
  register to the fallback chain the instance serves, Dutch first.
  - `["nl", "en"]` on `components.registers.dossiq`, and the register version
    moves 1.2.0 to 1.3.0 with it. That bump is load-bearing:
    `ImportHandler::importRegister()` returns on
    `version_compare($data['version'], $existing, '<=')` before touching the
    register's own fields, so the list under an unmoved version would reach no
    instance that already holds the register. Unlike the schema path, which
    compares content.
- [x] 3.1 `src/manifest.json` `#CaseTypeDetail`: surface the language tabs
  OpenRegister renders for a translatable property, and a completeness chip
  per language from `GET /api/registers/{id}/translation-stats`.
  - BUILT, BUT NOT THE WAY THE TASK DESCRIBES, because neither half of the
    description exists.
  - THERE ARE NO LANGUAGE TABS TO SURFACE. `@conduction/nextcloud-vue` 3.4.0
    contains no reference to `translatable`, `languageMeta` or
    `sourceLanguage`, so the library that builds every form on this page has
    never heard of the mark, and OpenRegister renders nothing into a leaf
    app's page. `CaseTypeTranslationsWidget` is the surface instead: a chip
    per declared language, a tab per language, one PATCH per edited label.
  - THE CHIP DOES NOT READ `translation-stats`, on purpose.
    `TranslationMapper::getCompletenessByObject()` counts every row with a
    non-empty value and never reads its status, so a label the Dutch moved out
    from under still counts as done. REQ-CFI-04 says a stale label is a wrong
    label. The chip reads the sidecar rows and applies that rule.
  - The PATCH carries the whole language map and not
    `X-Translation-Target-Language`: that header makes
    `normalizeTranslationsForSave()` build a fresh single-key map, and whether
    the Dutch value survives then depends on merge behaviour the browser
    cannot see.
- [x] 4.1 `tests/vitest/`: every property this change marks is still marked,
  no property of `case` itself is marked, and `x-translatable` appears
  nowhere in `lib/Settings/`. A case is not a label, and a prefixed key is
  not a declaration.
  - The register assertions live in
    `tests/Unit/Settings/TranslatableLabelsTest.php` (7 tests, 327
    assertions) rather than in vitest, because the files are PHP-side shipped
    configuration and the PHP suite is where a register regression is caught
    on every run. `tests/vitest/caseTypeTranslations.spec.js` (9 tests) covers
    the widget: the chip counts, the stale rule, the whole-map PATCH, the
    envelope-driven property list, the error state and the registry wiring.
  - Mutation checked twice. Dropping `sourceLanguage` from `caseType.purpose`
    reddens `testEveryTranslatableLabelDeclaresItsSourceLanguage` naming that
    property. Making `completenessOf()` ignore the outdated status reddens
    `counts a stale translation as missing` on
    `.toEqual({ translated: 1, total: 2 })`, not on a setup line.
- [x] 4.2 `tests/e2e/case-type-labels-are-translatable.spec.ts`: give a case
  type an English title, switch the interface language, the list shows it.
  - `tests/e2e/translatable-labels.spec.ts` is the spec, named for what it
    asserts. It asks a RUNNING OpenRegister whether `caseType.title` is in the
    translation projection, over the API it serves to everybody, and carries
    `identifier` as its control: a real property of the same schema, on the
    same object, in the same response, that is not translatable. If
    `identifier` ever appears in `languageMeta`, the envelope is answering
    about something other than the mark and the assertion above it means
    nothing.
