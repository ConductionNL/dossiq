# r21-list-search-labels

## Why

Two lists in the simple profile read differently from their boards:

- DqTaken: the search field said "Zoeken..." (the library default) where the board says "Zoek op taak of
  zaaknummer", and the due column said "Termijn" where the board says "Deadline". The column used the "Deadline"
  string, which this app translates as "Termijn" for cases.
- DqZaaktypen: the search field said "Zoeken in deze lijst" where the board says "Zoek een zaaktype".

## What changes

- Tasks gets `searchPlaceholder: "Search by task or case number"` (nl "Zoek op taak of zaaknummer") and its due
  column uses "Task deadline" (nl "Deadline").
- CaseTypes uses "Search a case type" (nl "Zoek een zaaktype"), a string the catalogue already had.
