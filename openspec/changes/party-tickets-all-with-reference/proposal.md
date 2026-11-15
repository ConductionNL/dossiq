# Proposal: party-tickets-all-with-reference

## Why

Boards DqContact and DqOrganisatie list a party's tickets with the handled ones under the open ones. Each row reads
"PQ-2026-0402 · Aanvraag · behandelaar Fatma Yildiz", and a handled ticket shows "Afgehandeld" in green with "Klaar 29 sep".
The side card "Open tickets" reads "PQ-2026-0402 · behandelaar Fatma Yildiz". Live listed only the open tickets, by type
alone (r29).

## What changes

- `PipelinqGateway::ticketsByMasterEntity(refs, status)` asks pipelinq's `byMasterEntity` with `closed` or `all`. `open`
  keeps `openByMasterEntity`, so an older pipelinq still serves the open read.
- `GET /api/parties/open-tickets/list` takes `status` (`open` by default, `closed`, `all`).
- The Tickets cards ask for `all` and read `{reference} · {ticketType} · handler {assigneeName}`. A resolved, closed or
  completed ticket is "Handled" in green, with "Done <resolvedAt>". The side open ticket reads
  `{reference} · handler {assigneeName}`.

Needs pipelinq `feat/ticket-leaf-closed-reference-assignee`. Without it, the Tickets card reads as unavailable and the
side card still shows the open ticket.

## Scope

`lib/Service/Pipelinq/PipelinqGateway.php`, `lib/Controller/PartyTicketController.php`, `src/manifest.json`, and two
translation keys.
