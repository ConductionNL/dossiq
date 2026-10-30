# Proposal: notices-table-reads-its-schema

## Why

DqTermijnen "Ingebrekestellingen en dwangsom" drew "Er is nog geen ingebrekestelling ontvangen" while OpenRegister holds
three notices of default. The `table` widget (CnObjectListWidget) reads `register`, `schema`, `filter` and `limit` from
its content; the manifest nested them under `source` (the object-table spelling), so the widget saw no schema and fetched
nothing (probed: no noticeOfDefault request on the page).

## What changes

The notices table names its register, schema, filter and limit on its content.
