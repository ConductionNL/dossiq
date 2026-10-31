# Proposal: case-pills-status-colour

## Why

r32 strict verdict on DqOrganisatie: the "Besluitvorming" pill is teal on the board (in the side card and the Zaken card),
and live drew it purple. The status types carry their colour (`colour`: teal, green ...). The pill takes it, so
"Afgehandeld" is green and "Besluitvorming" teal, with no per-name map and no per-case-type rule.

## What changes

The party pages' case pills (`contact-open-cases` on both pages, `side-open-case`) read `colorField: "status.colour"`
(nextcloud-vue record-list `colorField`). This replaces the `isFinalStatus` rule from party-cases-final-green.

## Scope

Config only (`src/manifest.json`).
