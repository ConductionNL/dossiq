# Proposal: case-type-task-meta-role-and-description

## Why

Board DqZaaktype draws each task card on Fasen en taken with a line that says who does it and how long it takes
("Intaker · 5 werkdagen"), or, for a task without a term, what it is ("Automatisch, bericht aan de aanvrager", "Alleen
in de variant Met welstand"). Live drew the Nextcloud group and "0 werkdagen" on every card
("zuiddrecht-vergunningen · 5 werkdagen", "0 werkdagen"), so the cards were one line where the board wraps to two, and
every card under Fasen en taken sat 11 to 12px high (r34: Varianten, Termijnen, Berichten aan de aanvrager, their table).

## What changes

- `GET /api/case-types/{id}/phases` gives each task `roleName`: the name of the role type the step routes to, in the
  reader's language, from the case type's role types and its ancestors'. Empty when the step routes to no role.
- The phase board's `itemMeta` reads the role and the term for a task with a term, the description for a task without
  one, the role when there is no description, and the group only for a task routed to no role.
