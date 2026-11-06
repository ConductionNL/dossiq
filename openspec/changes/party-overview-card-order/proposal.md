# Proposal: party-overview-card-order

## Why

Boards DqContact and DqOrganisatie stack the Overview tab as the Contact or Organisation card, Zaken, Tickets, then
Laatste contactmomenten. The pages put the contact moments before the tickets.

## What changes

The Overview tab of ContactDetail and OrganisationDetail lists `contact-open-tickets` before
`contact-latest-moments`. The two specs that pinned the old order now pin the board's.

## Scope

Config only (`src/manifest.json`).
