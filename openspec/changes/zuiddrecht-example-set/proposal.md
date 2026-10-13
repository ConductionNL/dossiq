---
kind: data
depends_on:
  - openregister/example-sets (ConductionNL/openregister#4603)
  - dossiq/resident-overview-offers-general-woo-request (ConductionNL/dossiq#3865, for the group behandelaars)
---

# Proposal: zuiddrecht-example-set

## Summary

Dossiq ships its part of the Zuiddrecht example set. One command on a fresh instance then shows a municipality with real-looking cases: `occ openregister:example-set:install zuiddrecht`.

## Why

The Zuiddrecht demo needs cases for more residents than Sanne de Vries. It also needs one Woo request that is fully handled and published, so a decision is visible. Today an administrator builds these by hand after every reset.

OpenRegister#4603 adds one loader for a story across apps. Each app ships `lib/Settings/example-sets/<set>.json`. The cast (people, organisations, groups) lives once, in OpenRegister. This change is dossiq's fragment.

## What changes

- `lib/Settings/example-sets/zuiddrecht.json`, a data file. No PHP changes.
- Group memberships for the cast employees:
  - `dossiq-medewerkers`: Daan, Esra, Thomas and Marleen.
  - `behandelaars`: Thomas, who handles Woo requests (dossiq#3865 gives the Woo case type `handling.defaultGroup: behandelaars`).
  - `dossiq-coordinators`: Marleen, the team lead.
  - `dossiq-sensitive`: Thomas, the Woo coordinator.
  - `kcc`: Daan, so the KCC citizen lookup lets him in.
  - `procest-admin`: Noor, functional admin of dossiq only, never a Nextcloud admin.
- Five cases, each on a case type dossiq already ships:
  - Henk Bakker: a parking permit he asked for by phone through the KCC, granted, with the contact moment.
  - Henk Bakker: a licence plate change, filed by his daughter Linda as his authorised representative (role Gemachtigde).
  - Fatima El-Amrani: a report about the public space, taken by phone, with a desk appointment planned.
  - Slagerij Van der Berg: a permit for a terrace canopy, filed with eHerkenning (`portalParty: kvk:...`).
  - CloudGemeente B.V.: a Woo request about the case system tender, handled, partly disclosed and published.
- The published Woo request carries its result and its Woo decision. The decision names the opencatalogi publication through a lookup on its title. So the fragment runs `after: ["opencatalogi"]`.
- Rows for the requesters in dossiq's `brpPerson` and `kvkCompany`, filled from the cast.

## Rules this data follows

- Procedures are configuration. The fragment adds no case type, status or result. It finds them by lookup on what dossiq ships.
- Only cast people and organisations. Names, BSNs, KVK numbers and e-mail addresses come from `{{cast:...}}`.
- Every date is relative to the install day.
- Sanne de Vries's five cases stay in portaliq's fragment. This file does not duplicate them.

## Not in this change

- The background service account. The loader cannot create a service account or set `dossiq background_service_account` yet. That is a request on the loader, listed in tasks.
- The absolute `wooPublicationUrl` on the published case. It needs the instance's base URL, which the loader cannot fill yet.
- A terrace permit case type. Dossiq ships none, so the Slagerij's case is a permit for a canopy over the terrace, which is a building activity.
- Citizen appointments. Dossiq has no appointment object. Fatima's appointment is a planned action on her case.
