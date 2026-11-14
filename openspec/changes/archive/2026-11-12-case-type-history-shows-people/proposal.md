# Proposal: case-type-history-shows-people

## Why

Board DqZaaktype "Historie" draws who changed the case type: "Versie 4 gepubliceerd, Lars de Koning · 2 okt" and "Varianten gewijzigd, Lars de Koning · 30 sep". Live listed the edits System leaves on every window refresh, upgrade and daily example-set refresh (12 on the dev instance, among them the daily shift of the set's relative dates), so the people's entries fell below the five shown. nextcloud-vue already hides updates that changed nothing; these System edits do change values, so they stayed.

## What changes

- The side History card of a case type leaves out entries by System (`excludeActors`). OpenRegister records those writes with cause `import` (example sets, openregister fix/example-set-writes-name-their-cause) or `migration`.
- The All history tab is unchanged and keeps every entry.
