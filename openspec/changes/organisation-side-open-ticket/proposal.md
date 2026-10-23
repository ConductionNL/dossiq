# Proposal: organisation-side-open-ticket

## Why

Board DqOrganisatie's side column shows "Open tickets" under "Lopende zaken": the organisation's open pipelinq ticket,
drawn like the open case above it. The page showed the tickets only in the Overview's Tickets card.

## What changes

OrganisationDetail gets a side card "Open tickets" right after "Lopende zaken": a record-list over the same endpoint as
the Tickets card (`/api/parties/open-tickets/list` by `masterEntityRef`), one row, the same pill, labels and term.
Without pipelinq the card is not drawn, like the Tickets card.

## Scope

Config only (`src/manifest.json`); existing translation keys.
