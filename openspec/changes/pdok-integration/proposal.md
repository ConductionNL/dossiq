# Proposal: PDOK Integration

## Summary

Introduce a centralised PDOK (Publieke Dienstverlening Op de Kaart) integration layer in Procest that consolidates address autocomplete, free-text + structured geocoding, reverse geocoding, BAG nummeraanduiding/verblijfsobject/pand lookup, kadastraal perceel resolution, and basemap tile configuration behind a small set of server-side services and a single admin settings page. Sister specs (`case-location`, `case-map-overview`, `map-component`) become consumers of these services rather than each carrying their own PDOK client.

## Problem

PDOK access today is scattered: `case-location` ships its own `PdokLocatieserverClient`, `map-component` hard-codes BRT Achtergrondkaart and Luchtfoto tile URLs, and BAG / Kadaster lookups have no canonical home. There is no shared cache, no shared rate limiting, no shared admin surface to switch endpoints between PDOK direct and an OpenConnector proxy, and no plan for degraded operation when PDOK is unreachable (a recurring failure mode for Dutch government apps during PDOK maintenance windows). Without a single integration layer we will duplicate retry/cache/error code across every consumer and force every municipality that requires proxied egress to configure the same source N times.

## Scope -- MVP

**In scope:**
- `PdokLocatieserverService` (suggest, free, lookup, reverse) — owns the v3_1 contract
- `PdokBagService` (nummeraanduiding by id, verblijfsobject details, pand footprint by id) via PDOK BAG WFS / OGC API Features
- `PdokKadasterService` (kadastraal perceel by aanduiding) — read-only
- Basemap configuration set (BRT Achtergrondkaart, BRT-A Grijs, Luchtfoto, NL Design System default) registered as `MapLayer` rows
- Admin settings tab "PDOK" — endpoint overrides, OpenConnector source toggles, cache TTL, rate-limit ceiling, outage banner copy
- Shared APCu cache layer (24 h for lookup/reverse/BAG, 5 min for suggest)
- Graceful degradation: when PDOK 5xx persists, surface "Achtergrondkaart tijdelijk niet beschikbaar" and short-circuit autocomplete to typed input only
- Refactor `case-location` to consume `PdokLocatieserverService` instead of its private client (REQ-CL-3 stays satisfied through delegation)

**Out of scope:**
- Off-line / pre-baked tile bundles for fully air-gapped deployments
- BGT (Basisregistratie Grootschalige Topografie) and BRO (Bodem/Ondergrond) — future spec
- Cadastral parcel **geometry** rendering — only the BRK identifier is resolved; geometry remains a follow-up
- WMS/WFS authoring UX for custom municipal layers (lives in a future `wms-wfs-layers` spec)
- Writing back to BAG / Kadaster — PDOK is read-only

## Dependencies

- `case-location` (consumer; client refactor lands as part of this change)
- `case-map-overview` (consumes basemap config)
- `map-component` (consumes basemap config + cluster colour scheme; not modified by this change)
- OpenConnector (optional proxied egress source per service)
- OpenRegister `geo-metadata-kaart` capability (ADR-022) for the `MapLayer` schema home
- PDOK Locatieserver v3_1, PDOK BAG WFS, PDOK Kadaster WFS — all open, no auth
