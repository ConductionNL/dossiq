# Proposal: open-raadsinformatie

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Rapportages › Open raadsinformatie

**Rationale:** Raadsinformatie-publicatie.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Why

Open Raadsinformatie (ORI) is the Dutch open standard for publishing council information — agendas, meetings, documents, votes, council members, fractions — as linked open data. Roughly 60% of municipalities run iBabs or NotuBiz, but those are closed SaaS silos. Existing ORI options (Open State Foundation aggregator, OpenRaadsinformatie.nl, Argu) are centralised, forcing municipalities to give up data sovereignty. Procest is the natural place to host ORI inside Nextcloud, treating council proceedings as cases with status, deadlines, and an audit trail, while exposing the underlying register publicly.

## What Changes

1. New OpenRegister-backed ORI register seeded from `lib/Settings/ori_register.json`, containing schemas for vergadering, agendapunt, raadsdocument, stemming, raadslid, fractie (and optional commissie/motie/amendement).
2. Public, unauthenticated read access (`authorization.read: ["public"]`) on all ORI schemas, with `searchable: true` for full-text search.
3. Demo/mock objects per schema for development, testing, and `clean-env` resets.
4. Procest case-lifecycle wrapper around vergaderingen: status tracking (gepland/lopend/afgerond/geannuleerd), deadline alerts, agenda-publication workflow.
5. RSS/Atom feed generation, multi-gemeente support, and source-tracking fields for connector-imported data (iBabs/NotuBiz via the `ibabs-notubiz-connector` spec).
6. Data-quality validation and a CLI repair step for idempotent provisioning.

## Impact

- **Affected projects**: procest (case wrapper, register seed), openregister (data layer), openconnector (data ingestion — separate spec).
- **Code surface**: register seed JSON, repair step, OAS exposure, public-access guard, RSS/Atom controller, multi-gemeente filter.
- **Dependencies**: OpenRegister (storage + OAS), OpenConnector (iBabs/NotuBiz import), Procest case engine.
- **Standards**: Open State Foundation ORI API, VNG Realisatie raadsinformatie, Popolo, Wet open overheid, JSON Schema validation.



## Design

# Design: open-raadsinformatie

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



## Tasks

# Tasks: open-raadsinformatie

## 1. Register Provisioning

### Task 1: Ship ori_register.json with all entity schemas
- **spec_ref**: `openspec/specs/open-raadsinformatie/spec.md#requirement-ori-register-must-be-provisionable-with-all-entity-schemas`
- **files**: `lib/Settings/ori_register.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN `occ openregister:load-register lib/Settings/ori_register.json` runs THEN a register with slug `ori` exists with all 7 schemas
  - All schemas have `authorization.read: ["public"]` and `searchable: true`
  - File passes `jq . ori_register.json` cleanly
- [ ] Author register file with schemas: vergadering, agendapunt, raadsdocument, stemming, raadslid, fractie, commissie
- [ ] Verify with `jq` and an importer dry run

### Task 2: Add repair step for idempotent provisioning
- **spec_ref**: `openspec/specs/open-raadsinformatie/spec.md#requirement-ori-register-must-be-provisionable-with-all-entity-schemas`
- **files**: `lib/Migration/RegisterOriRegister.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN repair step runs THEN register is provisioned
  - GIVEN an existing `ori` register WHEN repair step runs again THEN existing register is updated, not duplicated (via `@self` slug upsert)
- [ ] Implement RegisterOriRegister migration
- [ ] Register repair step in info.xml

## 2. Public Access and Search

### Task 3: Expose ORI register OAS publicly
- **spec_ref**: `openspec/specs/open-raadsinformatie/spec.md#requirement-public-access-and-transparency-woo-compliance`
- **files**: existing OasService (no new code; verify config)
- **acceptance_criteria**:
  - GIVEN ORI register provisioned WHEN unauthenticated client calls `GET /api/registers/ori/oas` THEN endpoint definitions for all ORI schemas returned
  - All read endpoints are accessible without auth headers
- [ ] Verify OasService picks up the register
- [ ] Add integration test confirming unauth read

### Task 4: Wire ORI schemas into search
- **spec_ref**: `openspec/specs/open-raadsinformatie/spec.md#requirement-search-and-filtering-across-ori-entities`
- **files**: rely on existing search infra; ensure `searchable: true` everywhere
- **acceptance_criteria**:
  - GIVEN seeded mock vergadering "Raadsvergadering 12 juni 2026" WHEN searching "Raad" via `/zoeken` THEN result appears
  - Filtering by `type=raadsvergadering` returns only matching records
- [ ] Confirm searchable=true on each schema
- [ ] Add a Newman smoke test asserting search hits

## 3. Vergadering Case Wrapper

### Task 5: Create VergaderingCaseService
- **spec_ref**: `openspec/specs/open-raadsinformatie/spec.md#requirement-vergadering-meeting-schema`
- **files**: `lib/Service/VergaderingCaseService.php`
- **acceptance_criteria**:
  - GIVEN a vergadering created with startDatum WHEN saved THEN a linked Procest case is created with status "gepland" and deadline = startDatum - 7 days
  - GIVEN startDatum reached WHEN nightly job runs THEN status transitions to "lopend"
- [ ] Implement createForVergadering(), advanceStatus(), checkDeadlines()
- [ ] Wire into vergadering object save lifecycle

## 4. Demo Data, Multi-Gemeente, Feeds

### Task 6: Seed demo objects in ori_register.json
- **spec_ref**: `openspec/specs/open-raadsinformatie/spec.md#requirement-demo-mock-data-for-development-and-testing`
- **files**: `lib/Settings/ori_register.json` (objects section)
- **acceptance_criteria**:
  - GIVEN clean-env reset WHEN ORI register imports THEN at least 1 vergadering, 6 agendapunten, 3 documenten, 1 stemming, 6 raadsleden, 2 fracties exist
  - All demo objects use the `@self` envelope and reference each other correctly
- [ ] Author demo `components.objects[]` entries

### Task 7: Implement RaadsinformatieFeedController
- **spec_ref**: `openspec/specs/open-raadsinformatie/spec.md#requirement-rss-atom-feed-generation-for-council-information`
- **files**: `lib/Controller/RaadsinformatieFeedController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN seeded vergaderingen WHEN GET `/apps/procest/feed/ori/vergaderingen.rss` called THEN valid Atom XML returned with latest 50 entries
  - GIVEN organisatie filter WHEN `?organisatie=X` provided THEN only that organisatie's records included
- [ ] Implement controller with feed renderer
- [ ] Register routes for vergaderingen / agendapunten / documenten feeds

### Task 8: Data quality validation job
- **spec_ref**: `openspec/specs/open-raadsinformatie/spec.md#requirement-data-quality-validation-for-ori-objects`
- **files**: `lib/Cron/OriDataQualityCheck.php`
- **acceptance_criteria**:
  - GIVEN a vergadering missing locatie/voorzitter WHEN nightly job runs THEN a data_quality_issues entry is written referencing the object
  - Admin dashboard surfaces the count of outstanding quality issues
- [ ] Implement nightly job
- [ ] Surface result on admin dashboard
