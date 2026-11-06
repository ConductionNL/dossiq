# The outbox shows its changes as cards that say what they hold

## Why

Board DqInspectie draws the device's outbox as cards: "Molenweg 4, checklist" over "18 antwoorden, 4 foto's", "Kerkplein 7, foto" over "Vraag 7", the conflict over its reason, each with its pill top right. Live drew a ruled list with "update · 24 okt, 10:12" under each label (COMPDIFF r25e).

## What changes

- syncQueue 1.2.0: optional `summary`, the device's words for what the change holds.
- The Synchronise frame draws the rows as cards, oldest first, the meta reading the summary, else the last error, else the operation and time.
- The Zuiddrecht outbox gets the board's summaries; the photo is queued at 10:13 so the order is the board's.

## Impact

register.d/40-mobiel-inspectie-offline.json, manifest.d/72-field-inspection.json, the Zuiddrecht set (needs a `--refresh` reinstall).
