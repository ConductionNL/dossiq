# case-type-overview-cards

## Why

Board dossiq/DqZaaktypeOverzicht draws the case type's Overview as Statussen, Regels, Rechten, then Privacy and
Vertalingen side by side. The page's Overview showed Statuses and Rights only: Privacy and Translations had a place in
the tab's layout but were not in its widget list, and there was no Rules card. Board DqZaaktype's phase board has
"Bekijken als flow", which opens the case type's workflow.

## What changes

- Overview shows Statuses, Rules, Rights, Personal data and Translations, in the board's order and widths.
- A Rules card lists the rules OpenRegister holds for the case schema (the inventory the Fields tab's rules widget reads),
  each with whether it is on.
- The phase board links "View as flow" to the case type's workflow (`workflowDefinition`); a case type without one
  shows no link.
- Dutch strings for the new labels (and the Fields tab's parent type card).

## Not built (no backing function)

- "Regel toevoegen": rules are added on OpenRegister's schema page; dossiq has no add-rule call.
- "Taak toevoegen" on the phase board: GET /api/case-types/{id}/phases is read-only and tasks are workflow steps;
  there is no add-task endpoint to wire it to.
