---
kind: code
---

# Proposal: r5-admin-settings-and-tour-tell-the-truth

## Summary

The round-4 cloud check (10 October 2026, dossiq 0.4.50-beta) read the admin
settings page, the getting-started tour and the Woo refusal grounds list, and
found places where the page says something that is not so, or says it in a way
nobody can read. Ruben approved fixing all of them.

## Why

Admin settings (`/settings/admin/dossiq`):

1. Search index: "Rebuild without locking" runs under its value "Yes". The
   label sits in a fixed 130px box that does not wrap.
2. The present/missing, Enabled and Active badges are nearly invisible. Since
   Nextcloud 32, `--color-success` and `--color-error` are light fills, not ink.
   The badges used them as text colour, or as a fill under white text.
3. The "Nextcloud 32 to 35" row has no badge, so it does not say whether this
   instance is in range.
4. "Last run" is empty. OpenRegister answers `lastRun` as a report object
   (`[]` when it never ran), and the panel printed it with `String()`.
5. Case type management draws all 96 properties of the case type schema as
   columns, alphabetically, with the title off-screen.
6. Four sections repeat their own name as an inner heading: VTH inspection
   checklists, AI-assisted processing, AWB term definitions, Mandate matrix.
7. A second "Case email: shared mailbox" section at the bottom shows only the
   application information, in a wider box. It is the form of the delegated
   `EmailSettings` registration, which mounts its own bundle. The real mailbox
   settings already sit in their own section of the main page.
8. Tenant onboarding shows an empty-state icon without text: the text was in
   a slot `NcEmptyContent` does not render.
9. Section headings are in Title Case.
10. "What shipped with dossiq" says nothing has been seeded while 24 case types
    exist. The shipped ledger only records starter sets; the 24 arrived with
    the register import.
11. "Apps dossiq works with" shows the old app names openconnector, docudesk,
    hrmq, decidesk and nldesign.

Tour:

12. Step 4 says "88 ship with the app"; the instance holds 24.
13. Step 7's title says "board", its text says "Dashboard".
14. Step 2 says the list shows every case, open and closed. The list says
    "2 of 2 open cases in your teams".

Woo refusal grounds (`/apps/dossiq/settings/woo-refusal-grounds`): the column
headers are empty and the page has no title.

## What changes

- Search index figures stack label over value and let the label wrap.
  `searchIndexStatus()` reduces OpenRegister's report to the time it finished
  (or started), `null` when it never ran; the panel shows that time as a date
  or "Never".
- Every status badge on the admin page pairs `--color-success`,
  `--color-error` or `--color-warning` as fill with the matching `-text`
  variable as ink. Text-only marks use the `-text` variable. Both pairs meet
  WCAG AA in the light and the dark theme.
- `Prerequisites::check()` reports the running Nextcloud major and whether it
  is in range; the Nextcloud row carries a present/missing badge like the PHP
  row.
- `Prerequisites::check()` gives every app row a `name` beside its `id`. The
  `id` stays the key `isInstalled()` is asked about; only the `name` shows the
  current product name (integriq, filinq, humaniq, decidiq, thematiq).
- Case type management shows five columns: title, identifier, published or
  draft, processing deadline, valid from. Title first.
- The four repeated inner headings go.
- `EmailSettings` keeps its delegated-settings registration but its form draws
  nothing. Its separate bundle `src/emailSettings.js` goes.
- Tenant onboarding's empty state passes its text as `name`.
- Admin settings headings are in sentence case, English and Dutch.
- "What shipped with dossiq" counts the objects of the chosen kind when the
  ledger is empty and says that they did not come from a starter set.
- Tour step 2 says what the list shows, step 4 drops the number, step 7 says
  "dashboard" in both title and text.
- The Woo refusal grounds list labels its columns and shows its title.

## Out of scope

- Any app id used in a lookup. `APPS_OPTIONAL` keys stay `openconnector`,
  `docudesk`, `hrmq`, `decidesk`, `nldesign` until each app's own `<id>` moves.
- The `WmsLayers` list has the same unlabelled-column defect; not in the
  round-4 findings.
