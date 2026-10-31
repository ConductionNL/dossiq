# Today's inspections are cards named by where they are

## Why

Board DqInspectie draws the inspector's day as cards: "Molenweg 4" with its pill top right, over "09:30 · Bouwtoezicht oplevering". Live drew a ruled list named by the case title ("Kapvergunning Molenweg 4") (COMPDIFF r26a: board card "Molenweg 4" missing on live, the list's structure differed).

## What changes

- fieldInspection 1.2.0: optional `location`, the place in words.
- The day list draws cards titled by the location, else the case title.
- The Zuiddrecht set gives today's four inspections their location.

## Impact

register.d/40-mobiel-inspectie-offline.json, manifest.d/72-field-inspection.json, the Zuiddrecht set (needs a --refresh reinstall).
