# Proposal: dwell-from-status-history-actor

## Why

DqProcesanalyse "Doorlooptijd per behandelaar" draws five handlers; live drew one row, "Niet vastgelegd". Cases without
status records (example data, imported cases) are read from their `statusHistory`, whose entries carry a status and a
moment but no actor, so every interval was unattributed.

## What changes

A record read from a case's status history is held by the actor its entry names (`actor` or `by`), else by the case's
handler (`assignee`). Status records keep their own actor, and time no record attributes is still named.
