# Proposal: party-tickets-link

## Why

Boards DqContact and DqOrganisatie head the Tickets card with "Alle 2 tickets bekijken", which opens the party's
tickets in pipelinq. The card showed "2 open" and no way to the tickets.

## What changes

The Tickets card on ContactDetail and OrganisationDetail gets a header link "View all {total} tickets" (nl "Alle
{total} tickets bekijken") to pipelinq's `/tickets/party/{ref}` with the party's `masterEntityRef`. The count moves
into the link's words; the "{total} open" note goes.

Needs nextcloud-vue feat/record-list-header-href-params (fills the href from the record) and pipelinq
feat/tickets-list-by-party (the route). On an older nextcloud-vue the link points at the literal `{ref}`, so merge
together with that release.

## Scope

Config (`src/manifest.json`) and one translation key.
