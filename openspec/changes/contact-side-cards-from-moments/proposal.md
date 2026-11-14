# Proposal: contact-side-cards-from-moments

## Why

Board DqContact draws two side cards from the person's contact history:

- Identiteit opens with "Ingelogd met DigiD, bij het contactmoment van 4 oktober" (a green shield) and shows
  "Bron: Basisregistratie personen" as a row beside BSN and Geheimhouding;
- Bereikbaar via says "Laatst contact op 4 oktober, via Mijn Zuiddrecht." before "Telefoon en e-mail staan niet in
  de basisregistratie."

The page shows neither the identification nor the last contact, and the source as a loose line.

## What changes

Both facts cards on ContactDetail read the person's newest contact moment as their `source`
(`contactmoment` filtered on `contact = @objectId`, newest first). Every entry about the person reads the page object (`from: "object"`), so a person without contact
moments still shows BSN, Geheimhouding, Bron and the base registration line.

- Identiteit: a `lead` naming how the person was identified (DigiD, BSN check, security questions; nothing for
  an unidentified moment) and the moment's date; "Source" becomes a row.
- Bereikbaar via: "Last contact on {date}, via {channel}".

Not built: the board's "Het tonen van het BSN wordt gelogd." The reveal is client-side (the BSN arrives with the
person) and dossiq does not log it, so the page does not claim it. The board's "Mijn Zuiddrecht, berichtenbox" row
needs the person's portal account, which dossiq does not read.

## Scope

Config only (`src/manifest.json`, l10n). `source` with `filter` + `sort`, `from: "object"`, `lead` and `map` are in
the released library.
