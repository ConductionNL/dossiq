# A case type has a short name

## Why
DqMijnWerk "Termijnen deze week" draws each deadline as the case number and the case type in a word:
"2026-4837 · Woo", "2026-4836 · Bezwaar". A case holds its case type's uuid and identifier ("woo-verzoek"),
and the case type only its full title ("Woo-verzoek"). A day of the strip is about 90px of text wide, so the
title does not fit and the identifier is not a name. The coordinator kept the board (design-system #810 is
held), so the strip has to read a name.

## What changes
- **Case type configuration.** `caseType.shortName` (caseType 1.46.0): an optional, translatable text of at
  most 30 characters, the case type's name for lines with little room. Both Woo request types set "Woo". An
  administrator sets it next to the title, on the create form and the case type page.
- **Page.** The simple Mijn werk week strip extends the case type and reads
  `["identifier", ["caseType.shortName", "caseType.title"]]`: the short name, else the title. Bezwaar has no
  short name and reads its title, "Bezwaar". This needs nextcloud-vue
  `feat/dq-c12-week-strip-meta-through-relation` (`source.extend`, meta alternatives); an older library ignores
  both and shows the number alone, as today.

## Out of scope
- Using the short name in other lists. Each place opts in through its own manifest.
