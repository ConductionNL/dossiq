# Proposal: contact-birth-longdate-social-history-stacked

## Why

r30:

- DqContact "Contact" read "14 mrt 1988"; the board writes "14 maart 1988". `date-long` abbreviates the month, and
  `longdate` writes it in full (with the year outside this year).
- DqSociaalDomein "Historie" drew a one-line audit row ("Toestemming 25-10-2026, 18:47:19"). The board stacks the moment
  and who under the action, as DqTaak's Historie does (`layout: "stacked"`).

## What changes

`contact-card` → `birth.date` uses `longdate`; `side-social-history` uses `layout: "stacked"`.

## Scope

Config only (`src/manifest.json`, `src/menu-layout.simple.json`).
