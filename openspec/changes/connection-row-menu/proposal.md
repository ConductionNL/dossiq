# Proposal: connection-row-menu

## Summary

DqInstellingen draws a "…" menu at the end of every Koppelingen row. Live had none. The menu holds what the page already offers per row: opening the connection's settings card on this page (the row's `settingsUrl`, the same place the row title links to). A connection without a settings card gets no menu.

## Scope

- `src/views/settings/AdminRoot.vue` (the connections table's `#row-actions` slot), vitest.
- No nc-vue change: CnWidgetObjectTable forwards CnDataTable's `#row-actions` slot, and CnRowActions renders an `href` entry as a real link.
