# Proposal: rules-tab-panel-heading

## Why

r29 on :8080 (DqZaaktypeRegels): the Rules tab drew no heading ("Regels op de zaak" and its line), so compdiff read the
card's title row as absent and its first row 616px in. A tab whose panel holds one widget heads the panel only when the
widget has a `description` (nextcloud-vue CnTabsWidget); the widget had a `subtitle` only. "Regel toevoegen" sat at the
row's end without an icon, where the board draws it under the heading at the start with a plus.

## What changes

- case-type-rules carries `description` (the same line as its subtitle), so the panel is headed.
- RulesTab's add button starts the row and carries the Plus icon.
