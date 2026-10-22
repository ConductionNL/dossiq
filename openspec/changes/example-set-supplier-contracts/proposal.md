# The Zuiddrecht set's supplier contracts

## Why

The board DqContracten draws the procurement contracts list with six contracts
from six suppliers, each with a status: active, a renewal or a termination
waiting on a decision in decidiq, or ended and winding down. The Zuiddrecht set
holds no supplier contracts, and the five on a development instance carry no
status at all, so the status column, its filter and the "Status: actief en
verlopend" chip have nothing to show.

The status values also had no `oneOf` labels. The Active line names a filter
value by its schema `oneOf` title, so a chip read the raw value
("Status: renewal-decision").

## What changes

- Six suppliers and six supplier contracts in dossiq's part of the Zuiddrecht
  set, as the board draws them: number, subject, supplier, value, end date and
  status. The KvK numbers are in an example range (`000020xx`), never a real
  company's.
- `supplierContract.status` gets `oneOf` labels with the titles its
  `x-enum-labels` already carry, and the schema version moves to 1.3.0 so the
  change re-imports on an existing instance.
