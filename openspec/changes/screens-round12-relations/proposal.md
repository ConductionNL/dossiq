# The Contacts and Organisations pages follow their boards

## Why

The boards DqContacten, DqContact, DqOrganisaties and DqOrganisatie are the
canon for the relations screens. The live pages carried the right records but
not the board's list setup: no count line, no quick filters, no masked BSN, no
addresses under the names, and no History on the record pages. The pages are
also meant to read the same as pipelinq's and shillinq's relation pages, so
they stay manifest configuration over the shared nextcloud-vue widgets.

## What changes

- Contacts: the board's count line, the Add person button, a quick filter for
  people under data protection, the name column with initials and the address
  under it, the BSN masked to its last four characters (a `masked` formatter),
  and the footer line.
- Organisations: the count line, Add organisation, quick filters by legal
  form, a building icon per row, sorted by name, and the footer line.
- Contact and organisation pages: a History tab and the History card last in
  the side column, as pipelinq's relation pages have; the address and KvK
  cards link to the cases map.

## Not in this change

The board's Tickets tab and Open tickets card read pipelinq's tickets, and
pipelinq's ticket names a pipelinq client, not a dossiq `brpPerson` or
`kvkCompany`, so there is no filter that answers it yet.
