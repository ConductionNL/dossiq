# Proposal: organisation-header-open-cases

## Why

Board DqOrganisatie names the open cases in the header line, "Organisaties / Buurtvereniging Oost · 1 lopende zaak",
where the page drew a purple pill "1 lopende zaak" beside the legal form (as DqContact does, and keeps doing).

## What changes

OrganisationDetail counts the organisation's open cases with `headerMetaCounts` and names them in the header line
through a plural `headerMeta` (`{ count, one, other, zero: "" }`); no line for none. The status pill goes; the same
count is now in the header line.

HOLD: needs nextcloud-vue feat/header-meta-plural (stacked on feat/r24-detail-header-meta-counts). Until that release
`tests/validate-manifest.js` refuses an object `headerMeta`.

## Scope

Config (`src/manifest.json`) and two translation keys; `relationPagesShape.spec.js` reads the count from either place.
