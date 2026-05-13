# Proposal: register-i18n

## Summary

Add V1 foundation for register content internationalization: a frontend utility for resolving language-tagged fields from OpenRegister objects, `translatable` flags on configuration schema fields in procest_register.json, and consistent glossary terms in the l10n translation files.

## Motivation

The spec requires that configuration objects (case types, status types, etc.) support multi-language content via OpenRegister's `translatable` flag. V1 scope is: mark fields as translatable in the schema, provide a frontend helper to resolve language-tagged values with fallback, and ensure the NL/EN glossary terms are consistent in l10n files.

## Affected Projects

- [x] Project: `procest` — Frontend i18n utility, register config updates, l10n additions

## Scope

### In Scope (V1)

- **REQ-I18N-001c/d**: Mark translatable fields in procest_register.json schemas
- **REQ-I18N-003a**: Frontend language resolution utility with fallback chain
- **REQ-I18N-007b**: Consistent UI label translations (l10n files)
- **REQ-I18N-008a/b/c**: Glossary terms in l10n translation files

### Out of Scope (V2)

- Language selector UI (REQ-I18N-004)
- API language negotiation (REQ-I18N-005)
- Admin translation management UI (REQ-I18N-006)
- Notification language (REQ-I18N-009)
- Pre-seeded translations in default data (REQ-I18N-010) — depends on OpenRegister translatable support

## Approach

1. Create `src/utils/i18nResolver.js` utility with `resolveTranslatable(value, locale, fallbackLocale)` function
2. Add `x-translatable: true` to translatable properties in procest_register.json
3. Add missing glossary terms to l10n/en.json and l10n/nl.json
