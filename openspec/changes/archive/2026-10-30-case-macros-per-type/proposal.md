# Proposal: case-macros-per-type

## Why

The case schema declares its macros, so every case type offered every macro: "Woo-verzoek afronden" on an objection.
Which macros a case offers is configuration of its case type, as its side cards (`sideCards`) and Overview cards
(`overviewCards`) are.

## What changes

- `caseType` gets `macros`: the keys of the case schema's declared macros a case of this type offers, in order
  (register.d/99-case-type-which-macros.json, caseType 1.28.0).
- The Woo request case type lists `finish-woo-request` and `suspend-for-supplement` (seed 1.4.11).
- The Macro's card reads the list through nextcloud-vue `macrosFrom: "@context.caseType.macros"`. A case type that
  lists none offers none.
