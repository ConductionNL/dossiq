# Register i18n (Internationalization)

The register i18n feature provides multilingual support for Dossiq, with Dutch (nl) and English (en) as the minimum required languages.

## Overview

As a Dutch government application, Dossiq must support Dutch as its primary language while also providing English translations for international users and developers.

## Scope

- **UI labels** -- All user interface elements translated.
- **Case type names** -- Translatable case type titles and descriptions.
- **Status labels** -- Translatable status names.
- **Error messages** -- Translated error and validation messages.
- **Documentation** -- Feature documentation available in multiple languages.
- **Email templates** -- Translatable notification templates.

## Implementation

- Uses Nextcloud's standard i18n infrastructure (gettext/l10n).
- Translation files stored in the `l10n/` directory.
- Translatable strings marked with `t()` in Vue components and `$this->l->t()` in PHP.

## Required Languages

- **nl** (Dutch) -- Primary language for government users.
- **en** (English) -- Required secondary language.

## Status

This feature is defined in the spec at `openspec/specs/register-i18n/spec.md` and is partially implemented.
