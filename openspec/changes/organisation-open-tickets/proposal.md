# Open tickets on the organisation list and page

## Why

DqOrganisaties draws an "Open tickets" column, and DqOrganisatie draws a
tickets card. The tickets are pipelinq's, and a pipelinq ticket points at a
pipelinq client, not at dossiq's `kvkCompany`. The designed cross-app join is
the master entity: records in different apps that describe the same party
share one `masterEntityRef`. dossiq's party schemas did not carry it.

## What changes

- `kvkCompany` (1.3.0) and `brpPerson` (1.4.0) gain `masterEntityRef`.
  Register 0.20.65.
- `PipelinqGateway::openTicketsByMasterEntity(refs)` reads pipelinq's ticket
  leaf (`TicketLeafProvider::openByMasterEntity`) as the calling user. It
  answers null when pipelinq is absent, predates the leaf, or has no ticket
  surface configured.
- `GET /api/parties/open-tickets?keys[]=` gives one count per master entity,
  and `GET /api/parties/open-tickets/list?key=` gives one party's open
  tickets. Both answer `available: false` without pipelinq.
- The organisation list gets an "Open tickets" column: an endpoint aggregate
  keyed on `masterEntityRef` that requires pipelinq (nc-vue
  feat/aggregate-from-endpoint).
- The organisation page and the contact page get a Tickets card in their Overview, visible only when
  pipelinq is installed.
- The Zuiddrecht example set gives the cast's companies and residents the same
  `masterEntityRef` as their pipelinq clients (pipelinq
  feat/ticket-leaf-by-master-entity).
