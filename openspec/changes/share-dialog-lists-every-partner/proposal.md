# The share dialog offers every partner organisation

## Why

The "Delen met een andere organisatie" dialog and the case sharing tab read OpenRegister's organisations index and kept the ones typed `partner`. That index lists only the organisations the user is a member of, and a partner is by definition somebody else's, so the select was always empty (DqZaakSamenwerken r29-r31: "Omgevingsdienst Zuid-Holland Zuid", type partner, exists on :8080 but was never offered; read on :8080: the index gives pieter 2 organisations, the search endpoint all 9 including the partner).

## What changes

- `utils/partnerOrganisations.js` reads `/api/organisations/search` (every organisation, up to 100) and keeps the partners; the share dialog and the sharing tab both use it.
