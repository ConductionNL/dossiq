# Frontend Locale Completeness

**Spec refs**: New capability. No existing spec owns en/nl translation-key parity; the existing
`register-i18n` capability (archived 2026-03-21) covered OpenRegister register/schema field
translations, not app UI string translations.

## ADDED Requirements

### Requirement: Translation keys are English source

Every `t('dossiq', ...)` / `n('dossiq', ...)` call site MUST use an English string as the
translation key. Dutch text MUST NOT be used directly as a translation key.

#### Scenario: Dutch-literal key is rejected

- **GIVEN** a developer writes `t('dossiq', 'Afgesloten')` where `'Afgesloten'` is the intended
  Dutch UI text
- **WHEN** the string is reviewed against this requirement
- **THEN** the call MUST use an English source key instead (e.g. `t('dossiq', 'Closed')`)
- **AND** `l10n/nl.json` MUST map that English key to the Dutch text (`"Closed": "Afgesloten"`)

#### Scenario: English-locale users never see raw Dutch text

- **GIVEN** the Nextcloud UI language is set to English
- **WHEN** any dossiq page renders
- **THEN** no visible string SHALL be untranslated Dutch text (e.g. `Aanbestedingen`, `Actie`,
  `Afgesloten`, `Adres bijgewerkt` rendering verbatim instead of an English equivalent)

### Requirement: en.json and nl.json key sets match

`l10n/en.json` and `l10n/nl.json` MUST contain the same set of translation keys — every key
present in one MUST be present in the other with a non-empty value.

#### Scenario: No missing Dutch translation

- **GIVEN** a translation key exists in `l10n/en.json`
- **WHEN** `l10n/nl.json` is checked for the same key
- **THEN** the key MUST be present with a non-empty Dutch translation
- **AND** a Dutch-locale user MUST NOT see the raw English key literal as fallback text

#### Scenario: Coverage check is automated

- **GIVEN** a new translation key is added to `en.json` without a corresponding `nl.json` entry
- **WHEN** `npm run test:l10n` is executed
- **THEN** the check MUST fail, reporting the specific key(s) missing from `nl.json`
