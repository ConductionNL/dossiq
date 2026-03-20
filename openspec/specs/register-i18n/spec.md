---
status: implemented
---
# Register Content Internationalization

## Purpose

Enable multi-language support for Procest's register objects, allowing users to view and manage case management content in their preferred language. Built on OpenRegister's register-i18n foundation (see `openregister/openspec/specs/register-i18n/spec.md`).

Case management terminology varies significantly between Dutch and English, and between municipalities. This spec ensures all configurable content (case types, status types, role types, result types, document types) can be presented in the user's language, while case instance data (the actual cases, decisions, notes) remains in the language of the originating municipality.

**Tender demand**: 9% of tenders (6/69) explicitly require multi-language support. All tenders implicitly require Dutch. Increasingly, tenders from border municipalities and international organizations require English and German.
**Standards**: BCP 47, W3C i18n, WCAG 2.1 SC 3.1.1/3.1.2, Nextcloud l10n framework
**Feature tier**: V1 (Dutch + English for type definitions), V2 (full language switching, API support, admin translation UI)

## Requirements

---

### REQ-I18N-001: Language-Tagged Case Type Fields

The system MUST support multi-language content for case type definitions via OpenRegister's `translatable` flag. Only configuration objects (types, statuses, roles) are translatable -- case instance data is not.

**Feature tier**: V1


#### Scenario I18N-001a: Case type title in Dutch and English

- GIVEN a case type with:
  - `title.nl` = "Omgevingsvergunning"
  - `title.en` = "Environmental permit"
  - `description.nl` = "Vergunning voor activiteiten die invloed hebben op de leefomgeving"
  - `description.en` = "Permit for activities affecting the living environment"
- WHEN a user with language preference "en" views the case type list
- THEN the system MUST display: "Environmental permit" as the title
- AND "Permit for activities affecting the living environment" as the description

#### Scenario I18N-001b: Case type with Dutch only (no English translation)

- GIVEN a case type with:
  - `title.nl` = "Bezwaarschrift"
  - `title.en` = (not set)
- WHEN a user with language preference "en" views the case type
- THEN the system MUST display: "Bezwaarschrift" (fallback to Dutch)
- AND a visual indicator MUST show that the content is in Dutch (e.g., a small "NL" badge or italic text)

#### Scenario I18N-001c: All translatable case type fields

- GIVEN the case type schema in OpenRegister
- THEN the following fields MUST be marked as `translatable: true`:
  - `title` -- display name of the case type
  - `description` -- explanation of the case type's purpose and scope
  - `purpose` -- formal purpose description
  - `trigger` -- description of what initiates this type of case
  - `subject` -- subject area description
- AND the following fields MUST NOT be translatable: `identifier`, `code`, `processingDeadline`, `confidentiality`, `archivalAction`

#### Scenario I18N-001d: Case type translations stored in OpenRegister

- GIVEN a case type object in OpenRegister
- WHEN translations are stored
- THEN the object MUST use OpenRegister's language-tagged field format:
  ```json
  {
    "title": {
      "nl": "Omgevingsvergunning",
      "en": "Environmental permit",
      "de": "Umweltgenehmigung"
    },
    "processingDeadline": 56
  }
  ```
- AND non-translatable fields MUST be stored as plain values (not language-tagged objects)

---

### REQ-I18N-002: Language-Tagged Status and Result Fields

Status types, result types, role types, and document types MUST support multi-language content for their display names and descriptions.

**Feature tier**: V1


#### Scenario I18N-002a: Status type translation

- GIVEN status types for "Omgevingsvergunning":
  - `{ title: { nl: "Ontvangen", en: "Received" }, description: { nl: "De aanvraag is ontvangen", en: "The application has been received" } }`
  - `{ title: { nl: "In behandeling", en: "In progress" }, description: { nl: "De aanvraag wordt beoordeeld", en: "The application is being assessed" } }`
  - `{ title: { nl: "Afgerond", en: "Completed" }, description: { nl: "De behandeling is afgerond", en: "Processing is complete" } }`
- WHEN an English-speaking user views a case status timeline
- THEN the timeline MUST show: "Received" -> "In progress" -> "Completed"

#### Scenario I18N-002b: Result type translation

- GIVEN result types:
  - `{ title: { nl: "Toegekend", en: "Granted" } }`
  - `{ title: { nl: "Afgewezen", en: "Rejected" } }`
  - `{ title: { nl: "Ingetrokken", en: "Withdrawn" } }`
  - `{ title: { nl: "Buiten behandeling", en: "Dismissed" } }`
- WHEN an English-speaking user views a case result
- THEN the result MUST be displayed in English

#### Scenario I18N-002c: Role type translation

- GIVEN role types:
  - `{ title: { nl: "Initiator", en: "Initiator" }, description: { nl: "De persoon die de aanvraag heeft ingediend", en: "The person who submitted the application" } }`
  - `{ title: { nl: "Behandelaar", en: "Case handler" }, description: { nl: "De medewerker die de zaak behandelt", en: "The employee handling the case" } }`
  - `{ title: { nl: "Belanghebbende", en: "Stakeholder" }, description: { nl: "Persoon met belang bij de zaak", en: "Person with interest in the case" } }`
  - `{ title: { nl: "Gemachtigde", en: "Authorized representative" } }`
- WHEN the ParticipantsSection component renders role labels
- THEN it MUST display role names in the user's preferred language

#### Scenario I18N-002d: Document type translation

- GIVEN document types:
  - `{ title: { nl: "Bouwtekening", en: "Building plan" } }`
  - `{ title: { nl: "Vergunningbesluit", en: "Permit decision" } }`
- WHEN an English-speaking user views the document list on a case
- THEN document type labels MUST appear in English

#### Scenario I18N-002e: Mixed-language display in case detail

- GIVEN a case with:
  - Case type title (translatable): "Environmental permit" (in English for the current user)
  - Case title (NOT translatable): "Aanvraag Dorpsstraat 1" (Dutch, as entered by the municipality)
  - Status (translatable): "In progress" (in English)
  - Notes (NOT translatable): "Bouwplan voldoet aan bestemmingsplan" (Dutch, as entered by the handler)
- WHEN the user views the case detail
- THEN translatable fields MUST appear in English
- AND non-translatable fields MUST appear in their original language (Dutch)
- AND the user MUST understand that case content is in the originating language

---

### REQ-I18N-003: Language Fallback Chain

The system MUST implement a deterministic language fallback chain when the preferred language translation is not available.

**Feature tier**: V1


#### Scenario I18N-003a: Standard fallback chain

- GIVEN a user with Nextcloud language preference "de" (German)
- AND a case type with translations in "nl" and "en" but NOT "de"
- WHEN the case type title is rendered
- THEN the fallback chain MUST be: `de` -> app default language -> `nl` -> `en` -> first available language
- AND the app default language MUST be configurable in Procest settings (default: `nl`)

#### Scenario I18N-003b: Fallback indicator display

- GIVEN a user with language preference "en"
- AND a status type with only Dutch translation: `{ title: { nl: "Heropend" } }`
- WHEN the status is displayed
- THEN the text "Heropend" MUST be shown
- AND a fallback indicator MUST appear: a small "NL" flag icon or `(nl)` text suffix
- AND a tooltip MUST explain: "This content is not yet translated to English"

#### Scenario I18N-003c: Missing all translations

- GIVEN a case type with `title` = `{}` (empty translation object)
- WHEN the title is rendered
- THEN the system MUST display the object's `identifier` or `code` as a fallback
- AND the fallback MUST be visually distinct (monospace font, gray color) to indicate it is a technical identifier

#### Scenario I18N-003d: Language preference from Nextcloud

- GIVEN user "pieter" has Nextcloud language set to "nl" in personal settings
- AND user "sarah" has Nextcloud language set to "en"
- WHEN both users view the same case type "Omgevingsvergunning" / "Environmental permit"
- THEN Pieter MUST see "Omgevingsvergunning"
- AND Sarah MUST see "Environmental permit"
- AND the language detection MUST use `OCP\IL10N` from the Nextcloud framework

---

### REQ-I18N-004: Frontend Language Switching

The system MUST allow users to switch the display language of translatable content without changing their Nextcloud language preference.

**Feature tier**: V2


#### Scenario I18N-004a: Language selector on detail pages

- GIVEN a case detail page showing a case of type "Omgevingsvergunning"
- AND the case type has translations in "nl", "en", and "de"
- WHEN the user clicks the language selector in the page header
- THEN a dropdown MUST show: "Nederlands", "English", "Deutsch"
- AND selecting "Deutsch" MUST immediately re-render all translatable content in German
- AND non-translatable content (case title, notes) MUST remain unchanged

#### Scenario I18N-004b: Language selection persists across navigation

- GIVEN a user who selected "en" as the display language on a case detail page
- WHEN the user navigates to the case list, then to a different case detail
- THEN the display language MUST remain "en" for all translatable content
- AND the language preference MUST be stored in the browser's localStorage: `procest_display_language = "en"`

#### Scenario I18N-004c: Language switch without page reload

- GIVEN a case detail page with status timeline, participants section, and documents
- WHEN the user switches the display language from "nl" to "en"
- THEN all translatable labels MUST update reactively (Vue reactivity system)
- AND no page reload or API re-fetch MUST be required
- AND the status timeline animation MUST NOT restart

#### Scenario I18N-004d: Language selector shows available languages only

- GIVEN a case type with translations in "nl" and "en" only
- AND a status type with translations in "nl", "en", and "de"
- WHEN the language selector is shown on a page displaying both
- THEN the selector MUST show the union of available languages: "nl", "en", "de"
- AND selecting "de" MUST show German for the status type and fallback to Dutch for the case type (with fallback indicator)

---

### REQ-I18N-005: API Language Support

The system MUST support language negotiation in API responses for both the ZGW API and OpenRegister API endpoints.

**Feature tier**: V2


#### Scenario I18N-005a: Accept-Language header support

- GIVEN a ZGW API client sends `GET /api/v1/zaaktypen` with header `Accept-Language: en`
- WHEN the response is built
- THEN translatable fields MUST be returned in English
- AND the response MUST include header `Content-Language: en`
- AND non-translatable fields MUST be returned as-is

#### Scenario I18N-005b: Query parameter override

- GIVEN a ZGW API client sends `GET /api/v1/zaaktypen?lang=nl`
- AND the `Accept-Language` header is set to "en"
- WHEN the response is built
- THEN the `lang=nl` query parameter MUST override the `Accept-Language` header
- AND translatable fields MUST be returned in Dutch

#### Scenario I18N-005c: All translations in API response

- GIVEN a ZGW API client sends `GET /api/v1/zaaktypen?lang=*` (wildcard)
- WHEN the response is built
- THEN translatable fields MUST be returned as language-tagged objects:
  ```json
  {
    "omschrijving": {
      "nl": "Omgevingsvergunning",
      "en": "Environmental permit"
    }
  }
  ```
- AND this format MUST be available for admin/configuration clients that manage translations

#### Scenario I18N-005d: Listing endpoint language consistency

- GIVEN a case type list endpoint returns 10 case types
- AND 8 have English translations, 2 do not
- WHEN the API client requests `Accept-Language: en`
- THEN the 8 translated case types MUST have English content
- AND the 2 untranslated case types MUST fall back to Dutch
- AND the response `Content-Language` MUST be "en" (primary language of the response)

---

### REQ-I18N-006: Admin Translation Management

The system MUST provide an admin interface for managing translations of case type definitions, status types, result types, role types, and document types.

**Feature tier**: V2


#### Scenario I18N-006a: Translation editor on case type detail

- GIVEN an admin navigating to Procest Settings > Case Types > "Omgevingsvergunning"
- WHEN the admin opens the "Translations" tab
- THEN the admin MUST see a table with:
  - Rows: each translatable field (title, description, purpose, trigger, subject)
  - Columns: each configured language (nl, en, de, ...)
  - Cell: the translated text with an edit icon
- AND empty cells (missing translations) MUST be highlighted in yellow

#### Scenario I18N-006b: Bulk translation for new language

- GIVEN all case types, status types, and result types have Dutch and English translations
- WHEN an admin adds German ("de") as a new supported language
- THEN a bulk translation editor MUST show all translatable fields across all types
- AND the editor MUST show the Dutch text as reference for each field
- AND the admin MUST be able to fill in German translations one by one or via paste

#### Scenario I18N-006c: Translation completeness dashboard

- GIVEN 15 case types, 45 status types, 15 result types, 8 role types, and 12 document types
- AND each has 5 translatable fields on average
- WHEN the admin views the translation completeness page
- THEN the page MUST show:
  - Per language: percentage of fields translated (e.g., "English: 78% (371/475)")
  - Per entity type: completion bar (e.g., "Case types: 15/15 fully translated")
  - A list of missing translations sorted by priority (title fields first)

#### Scenario I18N-006d: Import translations from CSV

- GIVEN a municipality has translations prepared in a spreadsheet
- WHEN the admin uploads a CSV with columns: `entity_type`, `entity_id`, `field`, `language`, `text`
- THEN the system MUST validate the CSV format
- AND apply all translations to the matching OpenRegister objects
- AND report: "45 translations imported, 3 skipped (entity not found), 0 errors"

#### Scenario I18N-006e: Export translations for external review

- GIVEN an admin wants to send translations to a translator
- WHEN the admin clicks "Export translations"
- THEN the system MUST produce a CSV with all translatable fields
- AND each row MUST include: entity type, entity ID, field name, and one column per configured language
- AND empty cells MUST indicate missing translations

---

### REQ-I18N-007: ZGW API Terminology Mapping

The system MUST maintain correct ZGW API field names in Dutch regardless of the user's display language. ZGW API field names (omschrijving, zaaktype, resultaat) are standardized in Dutch and MUST NOT be translated in API responses.

**Feature tier**: V1


#### Scenario I18N-007a: ZGW API field names remain Dutch

- GIVEN a ZGW API client sends `GET /api/v1/zaken/2026-042` with `Accept-Language: en`
- WHEN the response is built
- THEN ZGW field names MUST remain in Dutch: `omschrijving`, `zaaktype`, `resultaat`, `status`, `startdatum`
- AND only the VALUES of translatable fields (omschrijving text, zaaktype text) MUST be translated
- AND the JSON keys MUST match the ZGW API specification exactly

#### Scenario I18N-007b: Frontend label translation vs. ZGW field names

- GIVEN the Procest frontend displays case data
- WHEN the user's language is English
- THEN UI labels MUST be translated: "Case type" (not "Zaaktype"), "Status", "Result" (not "Resultaat"), "Start date" (not "Startdatum"), "Deadline" (not "Uiterlijke einddatum afdoening")
- AND these translations MUST come from the Nextcloud l10n framework (`.pot` / `.po` files), NOT from OpenRegister translatable fields
- AND translatable VALUES (case type title, status title) MUST come from OpenRegister's language-tagged fields

#### Scenario I18N-007c: Dashboard widget labels

- GIVEN the Procest dashboard widgets (`src/views/dashboard/`, `src/views/widgets/`)
- WHEN the user's language is English
- THEN widget titles MUST be translated: "My cases" (not "Mijn zaken"), "Overdue" (not "Te laat"), "Tasks" (not "Taken")
- AND case type names in widgets MUST use the translatable field from OpenRegister

---

### REQ-I18N-008: Case Management Terminology Glossary

The system MUST maintain a consistent terminology glossary mapping Dutch case management terms to English equivalents across all UI components and documentation.

**Feature tier**: V1


#### Scenario I18N-008a: Core entity terminology

- GIVEN the Procest l10n translation files
- THEN the following term mappings MUST be consistently applied:

| Dutch (nl) | English (en) | Context |
|------------|-------------|---------|
| Zaak | Case | Primary entity |
| Zaaktype | Case type | Case classification |
| Status | Status | Case lifecycle state |
| Statustype | Status type | Status definition |
| Resultaat | Result | Case outcome |
| Resultaattype | Result type | Outcome definition |
| Besluit | Decision | Formal decision on a case |
| Besluittype | Decision type | Decision classification |
| Rol | Role | Participant role in a case |
| Roltype | Role type | Role definition |
| Taak | Task | Work item within a case |
| Document | Document | Attached file |
| Behandelaar | Case handler | Primary case worker |
| Initiator | Initiator | Person who started the case |
| Belanghebbende | Stakeholder | Interested party |
| Gemachtigde | Authorized representative | Legal representative |

#### Scenario I18N-008b: Status labels consistency

- GIVEN the default status types in Procest
- THEN the following translations MUST be used consistently:

| Dutch | English |
|-------|---------|
| Ontvangen | Received |
| In behandeling | In progress |
| Wacht op informatie | Awaiting information |
| Heropend | Reopened |
| Afgerond | Completed |
| Afgebroken | Cancelled |

#### Scenario I18N-008c: Action button translations

- GIVEN the Procest UI action buttons and form labels
- THEN the following translations MUST be consistent across all views:

| Dutch | English |
|-------|---------|
| Nieuwe zaak | New case |
| Status wijzigen | Change status |
| Resultaat instellen | Set result |
| Besluit nemen | Make decision |
| Taak toewijzen | Assign task |
| Document toevoegen | Add document |
| Zaak afsluiten | Close case |
| Opslaan | Save |
| Annuleren | Cancel |

---

### REQ-I18N-009: Notification and Email Language

Notifications and email messages generated by the system MUST be in the recipient's preferred language.

**Feature tier**: V2


#### Scenario I18N-009a: Task assignment notification language

- GIVEN user "sarah" has language preference "en"
- AND a task is assigned to sarah on case "2026-042" (type "Omgevingsvergunning")
- WHEN the notification is generated
- THEN the notification MUST be in English: "New task assigned: Review application for Environmental permit (2026-042)"
- AND the case type name MUST use the English translation from OpenRegister

#### Scenario I18N-009b: Deadline reminder in user's language

- GIVEN user "pieter" has language preference "nl"
- AND case "2026-042" has a deadline approaching in 3 days
- WHEN the reminder notification is sent
- THEN the notification MUST be in Dutch: "Deadline nadert: Omgevingsvergunning 2026-042 -- uiterlijke einddatum over 3 dagen"

#### Scenario I18N-009c: Mixed-language team notification

- GIVEN a case with handler "pieter" (nl) and coordinator "sarah" (en)
- WHEN a status change notification is sent to both
- THEN pieter MUST receive: "Status gewijzigd: Omgevingsvergunning 2026-042 is nu 'In behandeling'"
- AND sarah MUST receive: "Status changed: Environmental permit 2026-042 is now 'In progress'"

---

### REQ-I18N-010: Pre-seeded Translations

The system MUST ship with Dutch and English translations for all default case type definitions, status types, result types, and role types included in the Procest repair step.

**Feature tier**: V1


#### Scenario I18N-010a: Default case types pre-translated

- GIVEN the Procest app is installed and the repair step (`InitializeSettings`) runs
- THEN all default case types MUST include both Dutch and English translations:
  ```json
  {
    "title": { "nl": "Omgevingsvergunning", "en": "Environmental permit" },
    "description": { "nl": "Vergunning voor activiteiten die invloed hebben op de leefomgeving", "en": "Permit for activities affecting the living environment" }
  }
  ```

#### Scenario I18N-010b: Default status types pre-translated

- GIVEN the repair step creates default status types
- THEN all status types MUST include Dutch and English as per the glossary in REQ-I18N-008b

#### Scenario I18N-010c: Default role types pre-translated

- GIVEN the repair step creates default role types
- THEN all role types MUST include Dutch and English as per the glossary in REQ-I18N-008a

#### Scenario I18N-010d: Translation data in register configuration

- GIVEN the register configuration at `lib/Settings/procest_register.json`
- WHEN the repair step imports the configuration
- THEN translatable fields in the schema definitions MUST specify `"translatable": true`
- AND default object data MUST include language-tagged values for all translatable fields

---

## Dependencies

- **OpenRegister register-i18n spec** (`openregister/openspec/specs/register-i18n/spec.md`): Foundation for language-tagged fields and translatable schema property flag.
- **Nextcloud l10n framework** (`OCP\IL10N`): For UI string translations (labels, buttons, messages) -- separate from register content i18n.
- **Procest repair step** (`lib/Repair/InitializeSettings.php`): Seeds default translations during app installation.
- **Procest register configuration** (`lib/Settings/procest_register.json`): Contains schema definitions and default data with translations.
- **Procest frontend stores**: Must resolve translatable fields using the user's language preference.
- **Vue reactivity system**: For live language switching without page reload.

## Current Implementation Status

**Not implemented.** No multi-language content support exists in Procest. All content is stored in a single language (typically Dutch). Case type definitions, status types, result types, role types, and document types are all single-language.

**Foundation available:**
- Nextcloud l10n framework is available for UI string translations (`.pot` / `.po` files).
- Procest has a `l10n/` directory with Dutch translations for UI strings.
- OpenRegister's `translatable` property flag is specified but not yet implemented.
- The repair step (`InitializeSettings`) and register configuration (`procest_register.json`) provide the data seeding infrastructure.

## Standards & References

- **BCP 47**: Language tag standard (nl, en, de, fr, etc.).
- **W3C Internationalization best practices**: Content negotiation, language tagging, fallback chains.
- **WCAG 2.1 SC 3.1.1** (Language of Page): HTML `lang` attribute must match the primary language.
- **WCAG 2.1 SC 3.1.2** (Language of Parts): Inline language changes must be marked with `lang` attribute.
- **Nextcloud l10n framework**: For UI string translations -- separate from register content i18n.
- **HTTP Accept-Language**: RFC 7231 content negotiation header.
- **HTTP Content-Language**: RFC 7231 response language indicator.
- **ZGW API specification**: Field names are standardized in Dutch and MUST NOT be translated in API responses.

## Specificity Assessment

This spec defines 10 requirements with 3-5 scenarios each, covering the full i18n lifecycle from data model through admin UI. The critical distinction between translatable type definitions and non-translatable case instance data is clearly delineated.

**Competitive context**: Dimpact ZAC supports Dutch and English in its Angular frontend. Flowable supports multi-language process definitions. Procest's approach of register-level i18n (vs. application-level i18n) enables translation management without code changes.

**Open questions:**
1. Should the language selector be a per-user persistent preference or a session-level override?
2. Should the system support right-to-left (RTL) languages in the future?
3. How should translatable fields interact with full-text search (search in all languages or only the user's language)?
4. Should the admin translation UI support machine translation suggestions (e.g., via DeepL API)?
