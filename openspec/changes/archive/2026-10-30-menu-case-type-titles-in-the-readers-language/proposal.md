---
kind: code
depends_on: []
---

# Proposal: menu-case-type-titles-in-the-readers-language

## Why

On the review instance (10 Oct, finding B2) every case type in the "My case types"
picker, in the chosen list and in the menu payload was called "Array".
`MenuCaseTypesService::currentCaseTypes()` cast the case type's `title` to a
string, and `title` is declared translatable: a row read through OpenRegister
carries it as a language map (`{"nl": "Kapvergunning"}`), and a `(string)` cast of
a PHP array is the literal "Array".

## What Changes

- `MenuCaseTypesService` resolves the title with the app's existing
  `Support\TranslatedText` helper: the reader's language, then Dutch, then the
  first text the map holds. A case type with no title at all falls back to its
  uuid, as before.

## Impact

- `lib/Service/MenuCaseTypesService.php` (one new constructor dependency, autowired).
- No schema, register or frontend change.
