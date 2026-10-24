# Proposal: example-set-board-saved-views

## Why

DqFilterBeheren and DqFilterInMenu draw Pieter Jansen's Weergaven tab with three own views (Termijn deze week, Wacht op
inwoner, Opgeschort) and one view a colleague shared with him under "Gedeeld met mij" (Woo-verzoeken team, by Anouk
Bakker, team Woo en informatie). The Zuiddrecht example set gives him the first two only, so "Gedeeld met mij" and
"Opgeschort" are missing on the live boards, and "Wacht op inwoner" reads "Zaken die wachten op de aanvrager" where the
board reads "Status wacht op inwoner, gedeeld met team Woo".

## What changes

- Pieter owns "Opgeschort" ("Status opgeschort, alle teams"): the case list's Suspended lens.
- Anouk Bakker owns "Woo-verzoeken team", shared to use with the group Woo en informatie, of which Pieter is a member:
  the default view "Woo requests"'s conditions.
- "Wacht op inwoner" takes the board's description.

Data only, in the dossiq fragment of the Zuiddrecht example set. Sharing an example-set view with a group needs
OpenRegister's example-set view shares, which the fragment already uses.
