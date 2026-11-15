---
kind: config
depends_on: []
---

# Proposal: columns-follow-the-case-type

Gap scan of the parity ledger, 2026-09-18, pack d, row 11.9 "Configurable list
columns per case type". Rated `no` for dossiq, `yes` for GZAC.

## Why

A permit and a complaint want different columns, and the Cases list shows the
same nine to both. The columns are declared once per page in
`src/manifest.json`, so the only way to see a permit's expiry date is to put
it on every case type's row.

The library half is done. nextcloud-vue PR 1213, "a folder sidebar scope
carries its own columns, sort and search", merged into `parity/round2` on
2026-09-18: `CnIndexPage` now reads `columns`, `sort` and `searchFields` from
the selected `folderSidebar` scope, `src/utils/scopeListLayout.js` resolves
them, and the manifest v2 schema accepts them. A scope without `columns`
inherits the page's, so nothing breaks by staying silent. That change's own
proposal names this row and names dossiq Cases and Tasks as the consumers.

dossiq already has the scope it needs. The Cases page and the Case types page
declare `folderSidebar` with `source: "register"`, `schema: "caseType"`,
`filterField: "caseType"`, so picking a folder already picks a case type.
Nothing declares columns against it.

`allowSavedViews: true` on six pages is not this. That is the per-user half,
a view someone saves for themselves. This is the admin declared half, and the
two are meant to stack.

## What changes

- Each `caseType` scope on `#Cases` may declare `columns`, `sort` and
  `searchFields`. The scope's columns replace the page's when a type is
  picked, and the All types folder keeps the page's.
- The same on `#Tasks`, where the scope is the case type of the task's case.
- The seeded case types get the columns their domain asks for: a permit shows
  the decision date and the expiry, a bezwaar shows the contested decision and
  the hearing date, a complaint shows the channel and the receipt date.
- A column naming a property the case type does not carry fails the manifest
  test, not the page.

## Where this sits

dossiq's umbrella `competitor-parity-2026-09` already carries row 11.9 in its
sibling-owned table, naming nextcloud-vue as the owner and the dossiq half as
"declare the columns per case type on Cases". This change is that sentence,
written out, now that the nextcloud-vue half has merged.

## Ownership

nextcloud-vue built the mechanism and it is merged. dossiq declares the
columns. No PHP, no new component.

## ADRs

- Company ADR-036 and ADR-049: declared in the manifest, no custom component.

## Capabilities

- Modified: `case-management`: the case list shows the columns of the type you
  picked.

## Impact

`src/manifest.json` pages `#Cases` and `#Tasks`,
`lib/Settings/register.d/*.json` where seeded case types declare their scope
columns, one vitest manifest test, one e2e spec. Requires
`@conduction/nextcloud-vue` at the version carrying PR 1213.
