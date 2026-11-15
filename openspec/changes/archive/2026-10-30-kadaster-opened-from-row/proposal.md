# Proposal: kadaster-opened-from-row

## Why

DqInstellingen lists Kadaster (BRK) en WOZ in the Koppelingen table ("Uit" while its source is "Geen") and shows its
settings card under the table only once that row is opened: "Kadaster (BRK) en WOZ instellen", "Geopend vanuit de rij
Kadaster (BRK) en WOZ". Live has no Kadaster row and draws the card always.

## What changes

- connections.json declares `kadaster` (after BRP), linked to `#section-kadaster`, with integriq's `switch` on
  `integration.brk.mode` and `offValues ["", "log"]`: a source of None reads switched off. A save of that key asks
  integriq to resolve the row again.
- The Koppelingen table's title cell links to the row's settings card in the same tab; a switched-off row reads Uit.
- The Kadaster card is "Kadaster (BRK) en WOZ instellen" and opens from its row (nextcloud-vue `openedFrom`).

## Scope

lib/Settings/connections.json, IntegrationStatusService (KEYS, SAVE_REQUIRED_KEYS), AdminRoot.vue, l10n. Needs
nextcloud-vue feat/r25-settings-section-opened-from-row and feat/r25-cell-link-same-tab.
