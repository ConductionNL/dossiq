# Proposal: case-leaf-by-master-entity

## Why

Pipelinq's client page (boards PqKlant and PqOrganisatie) lists the client's cases: a Zaken card in the overview, an Open zaken card in the side column and a Zaken tab. Pipelinq does not hold dossiq's party uuids. It holds the master entity its client shares with dossiq's brpPerson or kvkCompany (`masterEntityRef`). Dossiq already reads pipelinq's tickets this way (pipelinq's TicketLeafProvider); this is the same leaf in the other direction.

## What changes

- `OCA\Dossiq\Integration\CaseLeafProvider::byMasterEntity(ref, status)`: the cases whose requester is a party carrying that master entity, read as the calling user, open cases first by deadline, then closed cases newest first. Each row has the identifier, title, case type, status name and colour, whether it is final, deadline, end date, handler and the link to the case page.
- No route of its own: a host app resolves the class through the server container, as dossiq resolves pipelinq's leaf.
