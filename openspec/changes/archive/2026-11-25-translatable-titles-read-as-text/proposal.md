---
kind: code
depends_on: []
---

# Proposal: translatable-titles-read-as-text

## Why

A case type's `title` and a status type's `name` are declared `translatable`.
OpenRegister hands them back as a language map (`{"nl": "Ontvangen"}`), and
a PHP string cast of a map is the literal "Array". Two earlier fixes (#3332
for the rebind dialog, #3635 for the menu) moved single call sites onto the
shared reader. Review on :8098 on 11 October 2026 (N-2) still found:

- every move in the lifecycle dialog labelled "Array": the workflow backfill
  stored each transition's label as the cast of the target status name;
- 13 upgrade warnings about a shipped flow "Array — basis": the same backfill
  titled every basis template after the cast case type title;
- `Array to string conversion` in `CaseTypePortalWithdrawalListener`, which
  refused a case type import with "Een zaak in Array kan niet naar Array";
- the same notice from the term declaration reader, the case role
  vocabulary, the status lookup, the flow projection and the VTH seed.

## What changes

- Every place in `lib/` that string-casts a case type title, or the name of a
  status, result or role type, reads it through `LanguageMapText` (or
  `TranslatedText` where the text is for one reader): the reader's language
  where one is known, then Dutch, then the first text. 41 sites in 30 files.
- `LanguageMapText::textOr()` keeps the fallback a cast named, such as
  `(string)($row['title'] ?? $id)`.
- The workflow backfill names a basis template "{case type} (basis)", without
  the em-dash, and heals the templates it stored before: only a template it
  wrote (its marker description), only a label that is literally "Array",
  in place, through the partial write, keeping every step and move id.

## Out of scope

- Re-projecting flows. The flows made from a healed template keep the old
  name until `occ dossiq:workflows:migrate-to-flows` runs again, which
  updates each flow by its marker.
- Other translatable properties read elsewhere (descriptions, document type
  names). This change covers titles and names of the case type and the
  types it owns that were found casting.
