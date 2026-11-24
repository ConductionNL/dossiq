# Data subject requests and contracts get the lists their boards draw

## Why

Boards DqPrivacy and DqContracten draw two lists no page showed: the open
data subject requests, and the municipality's contracts with their suppliers.
The records already exist. A data subject request is a case that carries
`dataSubjectRequestType`; a contract is a `supplierContract` row and a
supplier a `supplier` row. So both are index pages over existing schemas,
configured, with no procedure code.

## What changes

- `PrivacyRequests` (`/privacy/requests`): the cases with a data subject
  request kind, quick filters per kind, the term, status and handler, and a
  tab beside it to the data breach register.
- `Contracts` (`/procurement/contracts`) and `Suppliers`
  (`/procurement/suppliers`): contracts with supplier, value, end date and
  status, quick filters for active, expiring and decision needed, and the
  suppliers beside them.
- `supplierContract` gains `status` (active, renewal decision pending,
  termination decision pending, ended), so the list can show and filter where
  a contract stands. Existing instances see it after the register re-imports.

## Depends on

The breadcrumb, the header meta line, the sibling-page tabs and the notice
band are nextcloud-vue keys in flight (lane L25). Until that release the pages
render without them; nothing breaks.
