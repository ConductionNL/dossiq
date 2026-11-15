# Proposal: example-set-own-saved-view

## Why

DqFilterBeheren, DqFilterBewerken, DqFilterDelen, DqFilterInMenu and DqFilterVerwijderen draw the cases list with the
side panel on Weergaven, where the handler's own views stand under "Mijn weergaven" with their row menu (Bewerken,
Delen, Toevoegen aan menu, Verwijderen). That menu only exists on a view the reader owns, and the example data gave
the handler the boards draw (Pieter Jansen) none, so four of the five boards could not be reached.

## What changes

- The Zuiddrecht example set declares one private saved view on the case list (`scope: dossiq/case`), owned by Pieter
  Jansen (`{{cast:pieter.uid}}`, OpenRegister `r21/cast-pieter`): "Termijn deze week", his open cases with a deadline
  within seven days, sorted on deadline ascending. Its conditions are the default view "Mine, due this week"'s own, so
  it names no status or case type id.
- Sharing it with a group and adding it to the menu are not seeded: OpenRegister's example-set views write name,
  description, owner, isPublic, query, presentation and alert only.
