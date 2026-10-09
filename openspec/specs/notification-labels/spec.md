# notification-labels Specification

## Purpose
What a user reads where dossiq names itself outside its own pages: a readable label for every notification rule in the notification settings, the installed version in the settings footer, and a title on every step of the getting-started tour.

## Requirements

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
state, and the bundle SHALL read `appVersion` from it in the browser through
the library's `appVersionDefine()`. A build against a library without that
helper SHALL fail.

#### Scenario: A release installed over an older build

- **GIVEN** dossiq 0.4.48-beta installed from a bundle built while info.xml said 0.4.47-unstable
- **WHEN** the person opens the settings modal
- **THEN** the footer reads "dossiq 0.4.48-beta…"

#### Scenario: A build against a library without the helper

- **GIVEN** an installed @conduction/nextcloud-vue whose `webpack` entry has no `appVersionDefine()`
- **WHEN** webpack loads its config
- **THEN** the build stops with an error that names the missing helper, and no bundle with a build-time version is written

### Requirement: Every tour step has a title

Every step of every tour in the manifest SHALL have a title with an English
and a Dutch entry.

#### Scenario: Step 2 of the getting-started tour

- **GIVEN** the getting-started tour at step 2
- **WHEN** the popover opens
- **THEN** it shows the title "All your cases" and a screen reader hears "Step 2 of 7: All your cases"
