# admin-settings Delta: admin-tabs-carry-the-settings-board-blocks

**Status**: draft

## ADDED Requirements

### Requirement: Integrations MUST list the apps dossiq needs and works with

The Integrations tab SHALL list Openregister and Integriq as needed and Filinq, Pipelinq, Portaliq and Humaniq as working alongside, each by its product name, with the app id it was found under (its canonical id when it is not installed) and a Present or Not installed pill.

#### Scenario: An app installed under its former id
@e2e exclude The pill is read from the page's initial state; tests/Unit/Settings/DependencyAppsTest.php covers the lookup.

- **GIVEN** an instance that has integriq installed as `openconnector`
- **WHEN** an administrator opens Integrations
- **THEN** Integriq reads "looked up as openconnector" and Present

### Requirement: Integrations MUST carry the terms, the queue urgency, the search index run and the Woo refusal grounds

After the Kadaster card the tab SHALL show one table of every statutory term (basis, duration, version, counts in, on a closed day, valid, status) with Add definition, Urgency in the queue beside the Search index, and the Woo refusal grounds with Add ground. Saving the urgency SHALL send only the values that changed.

#### Scenario: A search index that never ran
@e2e exclude tests/vitest/settingsBoardBlocks.spec.js asserts the values.

- **GIVEN** OpenRegister reports no maintenance run
- **WHEN** an administrator opens Integrations
- **THEN** Latest run reads Never, Duration "no run yet" and Indexed "nothing yet", never an empty value

#### Scenario: Update now
@e2e exclude Needs OpenRegister's run endpoint.

- **WHEN** an administrator presses Update now
- **THEN** dossiq asks OpenRegister to queue a maintenance run as a background job and says so, or shows OpenRegister's refusal

### Requirement: Advanced MUST show the search index rows and the command that rebuilds

The Advanced tab SHALL show, after the KCC settings, the rows in the tables and in the index when OpenRegister reports them, the last run with its duration, and `occ openregister:tables:search-index rebuild`. When OpenRegister does not answer the card SHALL say what went wrong instead of drawing a table of zeros.

#### Scenario: OpenRegister does not answer
@e2e exclude tests/vitest/settingsBoardBlocks.spec.js covers the rows; the notice is the component's error branch.

- **GIVEN** the search index status request fails
- **THEN** the card reads "OpenRegister does not answer" with the error, and no figures
