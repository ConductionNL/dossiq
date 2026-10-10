---
kind: code
depends_on: [receipt-and-start-dates-are-set-at-creation]
---

# Proposal: adopt-nextcloud-vue-2-79

dossiq moves to `@conduction/nextcloud-vue` 2.79.0 and takes two things that
release makes possible: the funnels on the Cases list, and a Woo question that
stays off the create form.

## Why

- The simple Cases list set `headerFilters: false` (#3314) on the reading that
  the design had no column filters. The DqZaken board draws a funnel on Zaak,
  Type, Status, Behandelaar and Termijn. The boards are the UI canon, so the
  list follows the board.
- dossiq answers the Woo `receiptDate` question itself when a case is created
  (`receipt-and-start-dates-are-set-at-creation`). That change listed hiding
  the question as out of scope, because nextcloud-vue had no way to keep one
  definition off the create form. 2.79.0 adds `hideOnCreate` on dynamic
  question definitions, so the form can stop asking for a date nobody needs
  to type.

## What changes

- `@conduction/nextcloud-vue` ^2.78.0 becomes ^2.79.0 (a minor, within ^2),
  and the vendored `tests/schemas/app-manifest-v2.schema.json` is refreshed
  from the release.
- `src/menu-layout.simple.json` drops `headerFilters: false` from the Cases
  page, so the library's per-column funnels show.
- The `propertyDefinition` schema declares `hideOnCreate` (boolean, default
  false) and moves from 1.5.1 to 1.6.0. Without the declaration OpenRegister
  drops the flag on import.
- The Woo `receiptDate` definition carries `hideOnCreate: true` in
  `lib/Settings/register.d/81-woo-verzoek.json` and
  `lib/Settings/templates/woo-verzoek.json`.

## Out of scope

- An admin control for `hideOnCreate` on the Properties tab beyond what the
  schema-driven form offers by itself.
- Any other 2.79.0 behaviour (manifest deltas reaching the navigation,
  `fitWidth` on object tables, Dutch strings for the files browser and the
  countdown, a paused auto-started tour, the files browser reading through
  OpenRegister's files API). dossiq takes those as the library ships them.
