# Tasks: register-i18n

## Implementation Tasks

- [x] **T01**: Create `src/utils/i18nResolver.js` with `resolveTranslatable()`, `getUserLocale()`, `resolveField()`
- [x] **T02**: Add `x-translatable: true` to translatable properties in procest_register.json
- [x] **T03**: Add glossary terms to l10n/en.json and l10n/nl.json

## Verification Tasks

- [x] **V01**: `resolveTranslatable` returns correct value for string input (pass-through)
- [x] **V02**: `resolveTranslatable` returns correct value for language-tagged object
- [x] **V03**: Fallback chain works: locale -> fallbackLocale -> nl -> en -> first available
- [x] **V04**: All translatable schema fields marked in procest_register.json
- [x] **V05**: Glossary terms present in both l10n files
