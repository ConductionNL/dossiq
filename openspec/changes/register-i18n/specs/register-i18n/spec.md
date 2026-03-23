# Delta: register-i18n

## Changes from base spec

### REQ-I18N-001c/d (IMPLEMENTED - schema flags)
- Added `x-translatable: true` to translatable properties in procest_register.json
- caseType: title, description, purpose, trigger, subject
- statusType: name, description
- resultType: name, description
- roleType: name, description
- documentType: name, description
- decisionType: name, description

### REQ-I18N-003a (IMPLEMENTED - frontend utility)
- Created `src/utils/i18nResolver.js` with:
  - `resolveTranslatable(value, locale, fallbackLocale)` — resolves language-tagged fields with fallback chain
  - `getUserLocale()` — gets user's locale from Nextcloud
  - `resolveField(obj, field, locale)` — convenience wrapper
  - `resolveText(obj, field, locale)` — template-friendly wrapper
- Fallback chain: user locale -> fallback -> nl -> en -> first available

### REQ-I18N-008a/b/c (IMPLEMENTED - glossary)
- Added 22 glossary terms to l10n/en.json and l10n/nl.json
- Covers: entity types, status labels, action buttons per the spec glossary tables
