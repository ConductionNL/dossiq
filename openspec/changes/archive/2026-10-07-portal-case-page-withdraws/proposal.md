---
kind: code
depends_on: []
---

# Proposal: portal-case-page-withdraws

## Why

A resident opened their Woo request from "Mijn zaken" on the portal site and found no way to withdraw it. The server accepted a withdrawal (`POST /portal/api/citizen/cases/dossiq/case/{id}/withdraw`), but portaliq's case screen, with the status, the answers a resident may change, the documents and the withdrawal, mounts only through a `citizenCase` page block. dossiq declared no pages, so portaliq built its default page for `mijnZaken`, which carries a plain `detail` block.

## What changes

- The resident contribution (audiences `citizen` and `client`) declares `pages`, one per listable collection, built the way portaliq builds its own: the first create action of the collection's schema, the table, the selected row.
- The `mijnZaken` page also carries a `citizenCase` block, so the case a resident opens from "Mijn zaken" shows portaliq's case screen under the detail card.
- Page ids stay the collection ids, so the site's routes do not move.
- The `mijnZaken` collection declares `statusLabelField: statusPublicLabel` (portaliq#1108), so "Mijn zaken" shows the status in words instead of its uuid.
- Supplier and inspector manifests do not change.

## Out of scope

- "This request has already been withdrawn." comes from portaliq's `CitizenWritableSetResolver` and has no Dutch translation in portaliq's `l10n/nl.json`; reported to portaliq.
