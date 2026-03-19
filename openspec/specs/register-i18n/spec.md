# Register Content Internationalization

## Purpose
Enable multi-language support for Procest's register objects, allowing users to view and manage case management content in their preferred language. Built on OpenRegister's register-i18n foundation (see `openregister/openspec/specs/register-i18n/spec.md`).

## Requirements

### REQ-I18N-001: Language-Tagged Fields
The following Procest-specific fields MUST support multi-language content via OpenRegister's `translatable` flag:

**Case types:**
- `title` — display name of the case type (e.g., "Omgevingsvergunning" / "Environmental permit")
- `description` — explanation of the case type's purpose and scope
- `purpose` — formal purpose description for the case type
- `trigger` — description of what initiates this type of case
- `subject` — subject area description

**Status types:**
- `title` — display name of the status (e.g., "In behandeling" / "In progress")
- `description` — explanation of what this status means

**Result types:**
- `title` — display name of the result (e.g., "Toegekend" / "Granted")
- `description` — explanation of the result type

**Role types:**
- `title` — display name of the role (e.g., "Behandelaar" / "Case handler")
- `description` — explanation of role responsibilities

**Document types:**
- `title` — display name of the document type
- `description` — explanation of what this document type contains

**NOT translatable:** Case data itself (case content, notes, decisions) is specific to one municipality/language context and MUST NOT be marked as translatable. Case data is created in the language of the municipality handling the case.

### REQ-I18N-002: Language Fallback Chain
- MUST follow the Nextcloud user's language preference
- MUST fall back: user language -> app default language -> nl -> en -> first available
- MUST display fallback indicator when showing non-preferred language

### REQ-I18N-003: Frontend Language Switching
- MUST show language selector on detail pages when translated content exists
- MUST preserve current language selection across navigation within the app
- Language switching MUST NOT require page reload

### REQ-I18N-004: API Language Support
- API responses MUST accept `Accept-Language` header
- API responses MUST include `Content-Language` header
- `?lang=nl` query parameter MUST override Accept-Language
- Listing endpoints MUST return content in requested language with fallback

## Current Implementation Status
Not implemented. No multi-language content support exists in Procest. All content is stored in a single language (typically Dutch). Case type definitions, status types, result types, role types, and document types are all single-language.

## Standards & References
- OpenRegister register-i18n spec (foundation)
- BCP 47 language tags (nl, en, de, fr, etc.)
- W3C Internationalization best practices
- Nextcloud l10n framework (for UI strings -- separate from register content i18n)
- WCAG 2.1 SC 3.1.1 (Language of Page) and SC 3.1.2 (Language of Parts)

## Specificity Assessment
Depends on OpenRegister's register-i18n being implemented first. App-level work is primarily frontend (language selector, fallback display) and API layer (Accept-Language routing). The distinction between translatable type definitions and non-translatable case data is critical -- only the "configuration" objects (types, statuses, roles) need translation, not the case instances themselves.
