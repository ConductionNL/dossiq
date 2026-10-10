# Design: menu-case-type-titles-in-the-readers-language

## Decision: reuse TranslatedText

`lib/Service/Support/TranslatedText.php` exists for exactly this mistake (it was
written for the change case type dialog, which showed "Array" the same way). It
reads the reader's language from the app's `IL10N`, so the picker follows the
user's Nextcloud language. No second resolver is added.

The sort runs on the resolved title, so the list is ordered by what the reader
sees.
