# Proposal: case-type-fields-tab-board-cards

## Why

Board DqZaaktypeVelden draws the Fields tab of a case type as two cards: Velden (the fields editor) and "Wie wat ziet
en invult" (the field rights), with the choice list and the parent type beside them. The live tab also stacked the case
type form (Zaaktype), the raw terms (Termijnen) and the case list columns (Kolommen in de zakenlijst) under them, three
cards the board does not draw (COMPDIFF r24: "extra on live").

## What changes

- The Fields tab holds `case-type-fields-editor` and `case-type-field-rules` only.
- A new overflow tab Properties (Eigenschappen), listed under the tab strip's Actions menu, holds `case-type-core`,
  `case-type-terms` and `case-type-list-layout`. Nothing is removed from the page.
- The written terms' Edit (Phases and tasks, Termijnen) opens the Properties tab, where the raw terms now are.
- The versions and data side cards also show beside the Properties tab.

## Reach

Config only (src/menu-layout.simple.json, the simple structure profile). No schema, PHP or library change.
