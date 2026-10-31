# The location dialog offers map layers out of the box

## Why

The location dialog lists the organisation's map layers (`wmsLayer`) beside the map. The Zuiddrecht example set held none, so the dialog read "No map layers are set up yet" where board DqZaakLocatie shows the aerial photo and the land-use plan, both on (COMPDIFF DqZaakLocatie, 23 Oct). A PDOK WMS layer would also have drawn blank: a WMS tile is an image, and dossiq's map policy allowed only the base-map tile hosts as image sources. A layer stored with type "WFS" (the schema's enum) was drawn as WMS, because the dialog compared it with lower-case "wfs".

## What changes

- The Zuiddrecht example set gains two active, default-on layers on PDOK: Luchtfoto (Actueel_orthoHR) and Omgevingsplan (the Ruimtelijke plannen enkelbestemming layer, the temporary part of the omgevingsplan).
- The map policy allows `https://service.pdok.nl` as an image source.
- The dialog reads a layer's type case-insensitively.

## Impact

Example set, `MapCspRegistrar`, `CaseLocationDialog.vue`. No schema or route change. Live needs a re-install of the Zuiddrecht set. Not built: the board's third layer "Bomenregister (WFS)": there is no national tree register service, and Zuiddrecht has no service of its own to point at.
