# Design: register-i18n

## Architecture

### Frontend i18n Resolution

New utility `src/utils/i18nResolver.js`:
- `resolveTranslatable(value, locale, fallbackLocale)` — resolves a potentially language-tagged field value
  - If value is a string, return it as-is (no translations)
  - If value is an object with language keys, resolve using fallback chain: locale -> fallbackLocale -> 'nl' -> 'en' -> first available
  - Returns `{ text: string, lang: string, isFallback: boolean }`
- `getUserLocale()` — gets the current user's locale from Nextcloud (`OC.getLanguage()`)
- `resolveField(object, field, locale)` — convenience wrapper

### Register Config Updates

Add `x-translatable: true` to these properties in procest_register.json schemas:
- caseType: title, description, purpose, trigger, subject
- statusType: title, description
- resultType: title, description
- roleType: title, description
- documentType: title, description
- decisionType: title, description

### l10n Updates

Add consistent glossary terms per REQ-I18N-008 to both en.json and nl.json.

## File Changes

| File | Change |
|------|--------|
| `src/utils/i18nResolver.js` | New utility module |
| `lib/Settings/procest_register.json` | Add x-translatable flags |
| `l10n/en.json` | Add glossary terms |
| `l10n/nl.json` | Add glossary terms |
