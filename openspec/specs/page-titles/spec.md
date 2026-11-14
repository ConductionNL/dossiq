# page-titles Specification

## Purpose
What dossiq does for page titles. The requirements were first written by the change `r6-dossiq-titles-related-cases-requests` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-r6-dossiq-titles-related-cases-requests/`) and cover: The browser tab names the page on screen; A list narrowed to one case type is named after it.

## Requirements

### Requirement: The browser tab names the page on screen

Every dossiq route SHALL set the document title to the translated title of the
manifest page it renders, followed by " - " and the title Nextcloud rendered
for the app ("Dossiq - <instance name>"). A route that renders no titled page
SHALL keep the rendered title unchanged. Page titles SHALL be in sentence
case.

#### Scenario: Opening the case list
<!-- @e2e exclude The title is set by the router's afterEach hook; proven by tests/vitest/pageTitle.spec.js against a real vue-router, and live on :8099 (.lane-logs/r6dq-title-*.png). -->
- **GIVEN** the server rendered the title "Dossiq - Conduction Nextcloud"
- **WHEN** a user opens the case list
- **THEN** the tab reads "Cases - Dossiq - Conduction Nextcloud"

#### Scenario: Moving from page to page
<!-- @e2e exclude Router hook; proven by tests/vitest/pageTitle.spec.js. -->
- **GIVEN** a user is on the case list
- **WHEN** they open a case and then the landing page
- **THEN** the tab reads "Case - Dossiq - Conduction Nextcloud" and then "My work - Dossiq - Conduction Nextcloud", never with two page names

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
