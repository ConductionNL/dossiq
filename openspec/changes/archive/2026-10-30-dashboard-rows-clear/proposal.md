# Dashboard rows under the cards above

## Why
DqMijnWerk draws "Zaken die u volgt" and "Mijn archiefbeoordelingen" as one row under My tasks and Recent activity, and DqMijnTeam "Zaken per status" and "Zaken per type" level under the cards above. Live, each rose under its own column (r34 -189.8, -23.8, -28 px).

## What changes
Those four placements carry `clear: true` (nextcloud-vue feat/r34-dashboard-clear-row): each starts below the lowest card of the earlier rows. An older library ignores the key.
