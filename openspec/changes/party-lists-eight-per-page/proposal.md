# Proposal: party-lists-eight-per-page

## Why

r32 strict verdicts: the pager of DqContacten stood 64px lower than on the board, and the pager of DqOrganisaties 890px lower. The boards page by eight ("8 van 187", "8 van 23"), while live paged by the library default of twenty (probed: 20 rows of 23 on the organisations list).

## What changes

The Contacts and Organisations index pages set `pagination: { limit: 8 }`.

## Scope

Config only (`src/manifest.json`).
