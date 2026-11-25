# Design: translatable-titles-read-as-text

## D-1. One reader, injected

`LanguageMapText` already holds the rule (reader's language, Dutch, first
text) and is the precedent from #3332 and #3635. Each class takes it as a
trailing constructor parameter with a default instance, the shape
`CaseTypeResolver` uses, so every existing caller and test builds the class
unchanged. A label shown to one reader with an `IL10N` at hand passes its
language code; a value written to storage is read language-neutrally, which
gives the Dutch text.

## D-2. Heal only what the backfill wrote

A template is healed only when its description is the backfill's marker,
and only fields that equal "Array" (the title when it starts with "Array ").
An administrator's template, or a basis template someone renamed, is never
rewritten. Step and move ids are kept, because cases and flows point at
them.
