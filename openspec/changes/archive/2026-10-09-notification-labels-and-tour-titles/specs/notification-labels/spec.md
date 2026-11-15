# notification-labels Delta: notification-labels-and-tour-titles

## ADDED Requirements

### Requirement: Every dossiq notification rule has a label

dossiq SHALL pass CnAppRoot a label for every rule it declares under
`x-openregister-notifications`, keyed `<schema slug>.<rule key>`, with an
English and a Dutch entry.

#### Scenario: The person opens their notification settings

- **GIVEN** the rule `case/caseAssigned`
- **WHEN** a Dutch-speaking person opens the notification pane
- **THEN** the switch reads "Een zaak wordt aan mij toegewezen", not "caseAssigned"

### Requirement: The settings footer shows the installed version

The dossiq page SHALL provide the installed version as the `version` initial
state, and the bundle SHALL read `appVersion` from it in the browser.

#### Scenario: A release installed over an older build

- **GIVEN** dossiq 0.4.48-beta installed from a bundle built while info.xml said 0.4.47-unstable
- **WHEN** the person opens the settings modal
- **THEN** the footer reads "dossiq 0.4.48-beta…"

### Requirement: Every tour step has a title

Every step of every tour in the manifest SHALL have a title with an English
and a Dutch entry.

#### Scenario: Step 2 of the getting-started tour

- **GIVEN** the getting-started tour at step 2
- **WHEN** the popover opens
- **THEN** it shows the title "All your cases" and a screen reader hears "Step 2 of 7: All your cases"
