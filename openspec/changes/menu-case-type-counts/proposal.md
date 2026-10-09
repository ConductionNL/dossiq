---
kind: code
depends_on: [case-types-in-my-menu, case-type-handling-teams]
---

# Proposal: menu-case-type-counts

The board `DqPersoonlijkeInstellingen` draws a count beside every case type in
"Zaaktypen in mijn menu": "Woo-verzoeken · 19 open zaken" in the list, and the
same beside each option of "Zaaktype toevoegen". `case-types-in-my-menu`
(dossiq#3530) and `case-type-handling-teams` (dossiq#3532) shipped the picker
without it.

## Why

A count tells a handler which case types carry their work before they put one
in the menu. It is also the board.

## What changes

- `GET /api/menu-case-types` answers `openCases` on every chosen and every
  available case type: the number of open cases of that type the user may see.
- The counts come from ONE aggregate query: an OpenRegister terms facet on
  `caseType` over the open cases, never one count per case type. Open means
  the population the dashboard counts: not at a final status, not at a status
  hidden from lists, not a draft.
- A case on an older version of a case type counts for the version in use, so
  a case type does not lose its cases the day a new version is published.
- When the facet cannot be read, `openCases` is `null` and the picker shows no
  number, rather than a 0 nobody counted.
- The picker shows "{n} open cases" (nl "{n} open zaken") beside each row and
  each option.

## Impact

- `lib/Service/MenuCaseTypesService.php`, `lib/Controller/MenuCaseTypesController.php`
- `src/views/settings/MenuCaseTypesSettings.vue`, `l10n/`
- Spec `case-type-navigation`: REQ-CTN-006 added.
