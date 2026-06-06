# Design: open-raadsinformatie

status: pr-created

## Architecture

The ORI implementation has two layers:

1. **Storage layer (OpenRegister)** — a public register `ori` with seven entity schemas (vergadering, agendapunt, raadsdocument, stemming, raadslid, fractie, commissie). The register is shipped as `lib/Settings/ori_register.json` in the same OpenAPI 3.0.0 + `x-openregister` extension format already used by `brp_register.json`, `kvk_register.json`, `bag_register.json`, `dso_register.json`. Provisioning is idempotent via `occ openregister:load-register`.

2. **Case-wrapper layer (Procest)** — vergaderingen are surfaced as cases with status (gepland / lopend / afgerond / geannuleerd), deadline alerts (agenda publication T-7), and an audit trail. Agendapunt and raadsdocument objects link to the vergadering case as supporting records.

### Data Ingestion

Data flows in via OpenConnector connectors for iBabs and NotuBiz (handled in the separate `ibabs-notubiz-connector` spec). Source-tracking fields on every ORI schema (`source`, `sourceId`, `sourceUrl`, `lastSyncedAt`) make imported records traceable and incrementally updatable. Demo/mock objects are seeded inside `ori_register.json` so a fresh `clean-env` install boots with at least one full vergadering, six agendapunten, three documenten, a stemming, six raadsleden, and two fracties.

### Public Access

All ORI schemas carry `authorization.read: ["public"]` and `searchable: true`. The OAS exposure is via the existing `OasService` generation mechanism; no bespoke public controller is needed. Per Woo compliance, no authentication is required for read endpoints; write endpoints stay restricted to authenticated council-clerk roles.

### Multi-Gemeente

Each ORI object carries an `organisatie` reference. Filtering by organisatie is the multi-tenant guarantee; the seed register ships with a single organisatie object that operators rename on first boot.

### RSS/Atom

A lightweight `RaadsinformatieFeedController` exposes `GET /apps/procest/feed/ori/{type}.rss` where `{type}` ∈ `{vergaderingen, agendapunten, documenten}`. The controller renders the latest 50 published records per type with proper Atom metadata and links to the public OAS endpoint.

### Data Quality

Validation runs on every write via JSON Schema rules in the register file (enums, formats, maxLength, required). A nightly job flags records missing recommended fields (locatie, voorzitter, fractie) and writes to a `data_quality_issues` log consumable by the admin dashboard.

## Dependencies

- OpenRegister (storage, OAS, public auth model, `@self` slug-based upsert).
- OpenConnector (iBabs/NotuBiz ingestion — separate spec).
- Procest case engine (vergadering lifecycle wrapper).
- Existing `OasService` for public API exposure.
- `RaadsinformatieFeedController` (new, small).

## Out of Scope

- iBabs/NotuBiz connectors themselves (separate `ibabs-notubiz-connector` spec).
- SPARQL endpoint / 5-star linked data (future change).
- Live-streaming integration with vergaderzaal AV-systems.
- Automatic Woo-publicatie naar PLOOI.
