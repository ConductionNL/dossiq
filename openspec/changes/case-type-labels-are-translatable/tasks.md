# Tasks: case-type-labels-are-translatable

Tier: V1. Kind: config. Row 11.13.

- [ ] 1.0 Rename every `x-translatable` to `translatable` in
  `lib/Settings/dossiq_register.json` (24), `dossiq_mock_register.json` (21),
  `register.d/30-beschikking.json` (1) and `register.d/48-starter-content.json`
  (4). Read the diff, not the match count: the prefix is a substring of the
  bare key and a careless replace writes `x-x-translatable`.
  - `@spec openspec/changes/case-type-labels-are-translatable/specs/case-configuration-i18n/spec.md`
- [ ] 1.1 Mark `translatable: true` with `sourceLanguage: "nl"` on
  `caseType.title` and `caseType.description` in
  `lib/Settings/dossiq_register.json`, if the rename did not already.
- [ ] 1.2 The same on `statusType.name` and `description`, `resultType.title`
  and `description`, `documentType.title`, `roleType.title` and
  `transition.label`.
- [ ] 1.3 Confirm against a running instance that OpenRegister accepts the
  pair: `sourceLanguage` without `translatable: true` is refused with
  `error.reason: 'sourceLanguage requires translatable: true'`, so a typo
  fails the import rather than passing silently.
- [ ] 2.1 The register declares its languages. Set `languages` on the dossiq
  register to the fallback chain the instance serves, Dutch first.
- [ ] 3.1 `src/manifest.json` `#CaseTypeDetail`: surface the language tabs
  OpenRegister renders for a translatable property, and a completeness chip
  per language from `GET /api/registers/{id}/translation-stats`.
- [ ] 4.1 `tests/vitest/`: every property this change marks is still marked,
  no property of `case` itself is marked, and `x-translatable` appears
  nowhere in `lib/Settings/`. A case is not a label, and a prefixed key is
  not a declaration.
- [ ] 4.2 `tests/e2e/case-type-labels-are-translatable.spec.ts`: give a case
  type an English title, switch the interface language, the list shows it.
