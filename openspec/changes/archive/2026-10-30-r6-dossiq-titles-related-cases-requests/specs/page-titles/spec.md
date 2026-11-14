# page-titles Delta: r6-dossiq-titles-related-cases-requests

## ADDED Requirements

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
