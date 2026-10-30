# page-titles Delta: r7-dossiq-filtered-title-board-config

## ADDED Requirements

### Requirement: A list narrowed to one case type is named after it

A route whose query holds `caseType=<id>` SHALL set the document title to the
label of the menu entry that opens the same page with the same `caseType`,
followed by " - " and the title Nextcloud rendered. When no menu entry names
that case type the title SHALL read "Cases of one case type" (nl "Zaken van
één zaaktype") followed by " - " and the rendered title. When the menu
changes, the title of the open route SHALL be set again.

#### Scenario: Opening a case type from My case types
<!-- @e2e exclude The title is set by the router's afterEach hook and the menu watcher; proven by tests/vitest/pageTitle.spec.js against a real vue-router, and live on :8099 (.lane-logs/r7-dq-after-filtered-list.png). -->
- **GIVEN** the menu holds the entry "Woo-verzoek" opening the case list with `caseType=<id>`
- **WHEN** a user opens that entry
- **THEN** the tab reads "Woo-verzoek - Dossiq - Conduction Nextcloud", not "All cases - Dossiq - Conduction Nextcloud"

#### Scenario: A case type the menu does not name
<!-- @e2e exclude Pure function; proven by tests/vitest/pageTitle.spec.js. -->
- **GIVEN** no menu entry opens the case list with `caseType=<id>`
- **WHEN** a user opens `/cases?caseType=<id>`
- **THEN** the tab reads "Cases of one case type - Dossiq - Conduction Nextcloud"

#### Scenario: The menu arrives after the page
<!-- @e2e exclude Router hook plus watcher; proven by tests/vitest/pageTitle.spec.js. -->
- **GIVEN** a user hard-loads `/cases?caseType=<id>` before the backend menu delta has merged
- **WHEN** the delta adds the entry for that case type
- **THEN** the tab changes to the entry's label
