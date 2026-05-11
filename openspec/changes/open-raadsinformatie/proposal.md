# Proposal: open-raadsinformatie

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
