# Register Content Internationalization

## Problem
Enable multi-language support for Procest's register objects, allowing users to view and manage case management content in their preferred language. Built on OpenRegister's register-i18n foundation (see `openregister/openspec/specs/register-i18n/spec.md`).
Case management terminology varies significantly between Dutch and English, and between municipalities. This spec ensures all configurable content (case types, status types, role types, result types, document types) can be presented in the user's language, while case instance data (the actual cases, decisions, notes) remains in the language of the originating municipality.
**Tender demand**: 9% of tenders (6/69) explicitly require multi-language support. All tenders implicitly require Dutch. Increasingly, tenders from border municipalities and international organizations require English and German.
**Standards**: BCP 47, W3C i18n, WCAG 2.1 SC 3.1.1/3.1.2, Nextcloud l10n framework
**Feature tier**: V1 (Dutch + English for type definitions), V2 (full language switching, API support, admin translation UI)

## Proposed Solution
Implement Register Content Internationalization following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the register-i18n specification.

## Success Criteria
#### Scenario I18N-001a: Case type title in Dutch and English
#### Scenario I18N-001b: Case type with Dutch only (no English translation)
#### Scenario I18N-001c: All translatable case type fields
#### Scenario I18N-001d: Case type translations stored in OpenRegister
#### Scenario I18N-002a: Status type translation
