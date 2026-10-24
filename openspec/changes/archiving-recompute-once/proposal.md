# The Archiving card offers one recompute control and the expected outcome

## Why

On the case Archiving tab the retention widget draws "Nominatie herberekenen" in its own title row, and a host header action drew a second one in an empty row above the card (COMPDIFF r24, DqZaakArchivering). The host action also skipped what REQ-ARCH-11 asks: it was not disabled for a reader without the archivist role.

## What changes

- The case page config drops the host header action on `case-archival-panel`; the widget's own control remains the only one.
- The board's subtitle moves into the widget content, where the widget draws it.

## Impact

`src/menu-layout.simple.json` only. No schema, route or library change.
