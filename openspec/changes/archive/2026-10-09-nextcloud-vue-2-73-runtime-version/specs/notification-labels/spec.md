# notification-labels Delta: nextcloud-vue-2-73-runtime-version

## MODIFIED Requirements

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
