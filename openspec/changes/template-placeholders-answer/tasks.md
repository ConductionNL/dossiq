# Tasks: template-placeholders-answer

Tier: V1. Kind: code. Size S.

- [x] 1.1 `buildVariableMap()` answers `startDatum`, `einddatum` and
  `behandelaar` as aliases of the canonical keys, from one named constant
  `DEPRECATED_ALIASES` (D-1).
- [x] 1.2 The shipped bodies in `EmailTemplateService::DEFAULT_TEMPLATES` (5
  occurrences) and in `lib/Settings/register.d/35-email-templates.json` (5
  occurrences) use the canonical names.
- [x] 1.3 `getAvailableVariables()` lists the canonical names only, and every
  name it lists is one the map answers (D-1).
- [x] 2.1 `tests/Unit/Service/TemplatePlaceholdersTest.php`: both sets derived,
  `collectUnresolved()` asked rather than re-implemented, the aliases
  subtracted before the shipped check, and the gate shown able to fail
  (D-2, D-3, D-5).
- [x] 3.1 `openspec validate template-placeholders-answer --strict`.
