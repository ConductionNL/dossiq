# Tasks: case-type-labels-are-translatable

Tier: V1. Kind: config. Row 11.13.

- [ ] 1.1 Mark `translatable: true` with `sourceLanguage: "nl"` on
  `caseType.title` and `caseType.description` in
  `lib/Settings/dossiq_register.json`.
  - `@spec openspec/changes/case-type-labels-are-translatable/specs/case-configuration-i18n/spec.md`
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
  and no property of `case` itself is marked. A case is not a label.
- [ ] 4.2 `tests/e2e/case-type-labels-are-translatable.spec.ts`: give a case
  type an English title, switch the interface language, the list shows it.
