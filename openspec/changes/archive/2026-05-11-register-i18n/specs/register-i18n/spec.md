# Delta: register-i18n

## ADDED Requirements
### Requirement: REQ-I18N — 001c/d (IMPLEMENTED - schema flags)
The system SHALL satisfy the behaviour described as "requirement".

- Added `x-translatable: true` to translatable properties in procest_register.json
- caseType: title, description, purpose, trigger, subject
- statusType: name, description
- resultType: name, description
- roleType: name, description
- documentType: name, description
- decisionType: name, description

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour

### Requirement: REQ-I18N — 003a (IMPLEMENTED - frontend utility)
The system SHALL satisfy the behaviour described as "requirement".

- Created `src/utils/i18nResolver.js` with:
  - `resolveTranslatable(value, locale, fallbackLocale)` — resolves language-tagged fields with fallback chain
  - `getUserLocale()` — gets user's locale from Nextcloud
  - `resolveField(obj, field, locale)` — convenience wrapper
  - `resolveText(obj, field, locale)` — template-friendly wrapper
- Fallback chain: user locale -> fallback -> nl -> en -> first available

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour

### Requirement: REQ-I18N — 008a/b/c (IMPLEMENTED - glossary)
The system SHALL satisfy the behaviour described as "requirement".

- Added 22 glossary terms to l10n/en.json and l10n/nl.json
- Covers: entity types, status labels, action buttons per the spec glossary tables

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour
