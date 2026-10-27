# Party address in two lines of one block

## Why

Boards DqContact (Woonadres) and DqOrganisatie (KvK en adres) write the street and the postcode with place as two lines of one block, 22px apart, and put a map pin before "Zaken op de kaart". Live put the side card's gap between the lines, which pushed every side card below them down (r33: DqOrganisatie Hoofdcontact +10, Lopende zaken +18; DqContact Woonadres, Historie).

## What changes

- The second address line joins the first with a line break (`joinPrevious`, `joinSeparator: "\n"`, nc-vue 24t2j).
- The map link carries the MapMarkerOutline icon.
