# Design: pdok-integration

## Architecture Overview

PDOK integration is implemented as three sibling services behind one admin settings tab, all sharing a single cache decorator and a single rate-limit guard. Every consumer in Procest (case-location, case-map-overview, map-component) talks to these services rather than directly to `service.pdok.nl` or `api.pdok.nl`. When the admin configures an OpenConnector source for any PDOK service, all outbound HTTP from that service is rerouted through OpenConnector; otherwise the services call PDOK directly.

```
Consumers
├── LocationService            (case-location)
├── CaseMapOverviewService     (case-map-overview)
└── MapComponent (Vue)         (map-component, basemap config only)

Services (this change)
├── PdokLocatieserverService   ── suggest, free, lookup, reverse        (v3_1)
├── PdokBagService             ── nummeraanduiding, verblijfsobject, pand
└── PdokKadasterService        ── kadastraal perceel by aanduiding

Decorators
├── PdokCache (APCu)           ── lookup/reverse/BAG 24 h, suggest 5 min
└── PdokRateGuard              ── per-service token bucket, default 10 rps

Admin
└── PdokSettingsTab            ── endpoint overrides + OpenConnector toggles
```

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Service/Pdok/PdokLocatieserverService.php` | suggest, free, lookup, reverse against v3_1 |
| `lib/Service/Pdok/PdokBagService.php` | nummeraanduiding lookup, verblijfsobject detail, pand footprint |
| `lib/Service/Pdok/PdokKadasterService.php` | kadastraal perceel by aanduiding |
| `lib/Service/Pdok/PdokCache.php` | APCu wrapper, per-method TTL |
| `lib/Service/Pdok/PdokRateGuard.php` | token bucket, configurable ceiling |
| `lib/Service/Pdok/PdokSettingsService.php` | reads/writes PDOK endpoint config from IAppConfig |
| `lib/Controller/PdokController.php` | proxies `/suggest`, `/reverse`, `/bag/*` for the frontend |
| `lib/Repair/SeedPdokBasemaps.php` | seeds the four default `MapLayer` rows on install/update |
| `src/views/settings/tabs/PdokSettingsTab.vue` | admin UI for endpoint overrides + outage banner copy |
| `src/services/pdokApi.js` | frontend client used by autocomplete + map preview |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Service/LocationService.php` | swap private `PdokLocatieserverClient` for `PdokLocatieserverService` injection |
| `lib/Settings/procest_register.json` | extend `MapLayer` schema if missing fields surface during seeding |
| `appinfo/routes.php` | register `/api/pdok/*` routes |
| `lib/Service/SettingsService.php` | add `pdok_*` config keys (endpoints, OpenConnector sources, cache TTL, rate ceiling) |

## Data Model

### PDOK config (IAppConfig, app namespace `procest`)

| Key | Type | Default | Purpose |
|-----|------|---------|---------|
| `pdok_locatieserver_endpoint` | string | `https://api.pdok.nl/bzk/locatieserver/search/v3_1` | base URL for Locatieserver |
| `pdok_bag_endpoint` | string | `https://service.pdok.nl/lv/bag/wfs/v2_0` | base URL for BAG WFS |
| `pdok_kadaster_endpoint` | string | `https://service.pdok.nl/kadaster/kadastralekaart/wfs/v5_0` | base URL for Kadaster WFS |
| `pdok_locatieserver_source` | string | `` | OpenConnector source slug; empty = direct |
| `pdok_bag_source` | string | `` | idem for BAG |
| `pdok_kadaster_source` | string | `` | idem for Kadaster |
| `pdok_cache_lookup_ttl_seconds` | integer | `86400` | TTL for lookup/reverse/BAG |
| `pdok_cache_suggest_ttl_seconds` | integer | `300` | TTL for suggest |
| `pdok_rate_ceiling_rps` | integer | `10` | per-service ceiling |
| `pdok_outage_banner_nl` | string | `Achtergrondkaart tijdelijk niet beschikbaar` | shown when 5xx persists |
| `pdok_outage_banner_en` | string | `Basemap temporarily unavailable` | i18n companion |

### Seeded `MapLayer` rows (via repair step)

| title | type | isBaseLayer | isDefault | Notes |
|-------|------|-------------|-----------|-------|
| BRT Achtergrondkaart | WMTS | true | true | Default base layer |
| BRT Achtergrondkaart Grijs | WMTS | true | false | Print-friendly fallback |
| Luchtfoto | WMTS | true | false | Aerial imagery, most recent vintage |
| NL Design System | WMTS | true | false | Government-themed base, used when `theme = nldesign` |

## API Surface

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/pdok/suggest?q=...&fq=...` | Locatieserver suggest passthrough |
| GET | `/api/pdok/free?q=...` | Locatieserver free passthrough |
| GET | `/api/pdok/lookup?id=...` | Locatieserver lookup by result id |
| GET | `/api/pdok/reverse?lat=...&lng=...` | Locatieserver reverse |
| GET | `/api/pdok/bag/nummeraanduiding/{id}` | BAG nummeraanduiding lookup |
| GET | `/api/pdok/bag/verblijfsobject/{id}` | BAG verblijfsobject detail |
| GET | `/api/pdok/bag/pand/{id}` | BAG pand footprint |
| GET | `/api/pdok/kadaster/perceel?aanduiding=...` | BRK perceel by aanduiding |
| GET | `/api/pdok/basemaps` | Active basemap config for the map component |
| GET | `/api/pdok/health` | Liveness check; used by the map component to decide whether to render the outage banner |

## Caching & Rate-Limit Strategy

- Lookup/reverse/BAG/Kadaster responses cached for 24 h keyed on the full request signature.
- Suggest cached for 5 min keyed on `q` plus active `fq` filters.
- Cache backend is APCu (already used elsewhere in Procest); a hit bypasses the rate guard.
- The rate guard uses a token bucket at `pdok_rate_ceiling_rps` per service; bursts are smoothed; a denied call returns the cached fallback when available, otherwise `429 pdok.rate-limited`.

## Outage Handling

The Locatieserver service tracks consecutive 5xx responses in a rolling window. After 3 failures within 60 s the service enters a 5 min "degraded" state: `/api/pdok/health` reports `degraded`, the map component shows the configured outage banner, and `/api/pdok/suggest` short-circuits to an empty result set so the user can still type a free-text address and save the case (which the LocationService will then store with `source = free` instead of `bag`). The service self-clears the degraded state on the first successful response after the cool-down.

## Auth

All PDOK endpoints used here are open, anonymous, and rate-limited by the public IP. No keys are stored. The only auth path is the OpenConnector source slug, which carries its own credentials inside OpenConnector's vault — never duplicated in Procest config.
