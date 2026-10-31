# A case type carries how its list looks

## Why

Boards DqWooVerzoeken and DqKlachten are the Cases list narrowed to one case
type, with that case type's own heading, count line, quick filters, add
button, footer line and, for complaints, a card under the list. The case type
already carries its columns in `x-index`; the rest is presentation of the same
scope. Keeping it on the case type record keeps procedures as configuration:
the Cases page stays one generic page, and a municipality edits the look of a
case type where it edits the case type.

## What changes

- `caseType.x-index` declares `title`, `countText`, `addLabel`, `footerNote`,
  `breadcrumb`, `headerMeta`, `quickFilters` and `afterList` beside `columns`,
  `defaultSort` and `searchFields`.
- The seeded Woo request and complaint case types fill the block in.

## Depends on

nextcloud-vue reads the new keys off the scope (lane L25, per-scope
presentation). Until then the list keeps the page's own heading and lenses and
applies the columns, sort and search fields as today. Existing instances see
the seed after the register re-imports (register 0.20.48).
