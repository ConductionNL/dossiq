# Proposal: case-type-fields-origin-and-list

## Why

Board DqZaaktypeVelden's Velden table ends in Herkomst ("Eigen veld", "Geërfd van Omgevingsvergunning") and In de
lijst (whether the case list shows the field), and lists the inherited fields beside the type's own. The table listed
only the own fields and had neither column (coordinator decision, 23 Oct: build it on the library).

## What changes

- `case-type-fields-editor` adds the blueprint's inherited and shared fields read only (`readOnlyRows`, nextcloud-vue
  `feat/object-list-read-only-rows`); the own fields keep editing.
- Columns Origin (`fieldOrigin`: Own field / Inherited from {caseType} / Shared) and In the list (`fieldInCaseList`:
  the case type's `x-index.columns` holds the field's name, read from the page record through nextcloud-vue
  `feat/cell-formatter-page-record`).
- l10n: Inherited from {caseType}, Own field.

STACKED on r24/case-type-fields-columns. LAND TOGETHER with both nextcloud-vue branches: without the page record
every In the list cell reads No.

## Reach

Config, formatters, l10n.
