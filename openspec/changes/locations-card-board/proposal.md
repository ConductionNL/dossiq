# Proposal: locations-card-board

## Why

Boards DqZaak and DqZaakMeer draw the "Locaties" card with a short map (about
120px) carrying zoom (+, -) and fit-all buttons in its corner, the location's
address under it and "1 locatie" in the title row. Live drew a 260px map with
no buttons (r24/location-maps-preview had taken them all off), which pushed the
card to 333px against the board's 224px.

## What changes

The card's map is 120px high with zoom and fit; locate and fullscreen stay
off. In the board look its corners are rounded (nextcloud-vue
fix/r24-board-map-rounded). The address line and the count are data
(a case-location on the case) and the count subtitle already shipped.
