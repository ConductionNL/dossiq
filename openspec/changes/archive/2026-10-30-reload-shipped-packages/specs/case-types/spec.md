# Case types

## ADDED Requirements

### Requirement: An administrator reloads the shipped templates

dossiq SHALL offer `POST /api/packages/reload`, which installs every shipped
package that is installed on this instance or marked `install: auto`, one at a
time through the same install as a single package (REQ-CT-49), and answers one
outcome per package. A package at the shipped version SHALL write nothing and
answer `current`. The endpoint SHALL refuse anyone who is not an administrator
before any package is read or written, and SHALL answer 500 when a package failed,
else 200. The case type list SHALL offer it in its Actions menu as "Reload
templates", after a confirmation.

#### Scenario: Only installed and automatic packages are reloaded
@e2e exclude no user interface beyond a menu entry; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testAReloadInstallsWhatIsInstalledOrAutomaticAndNothingElse

- **GIVEN** an on-request package that was never installed
- **WHEN** an administrator reloads the templates
- **THEN** nothing SHALL be written
- **AND** after the package is installed once, a reload SHALL answer `current` for it and write nothing

#### Scenario: A non-administrator changes nothing
@e2e exclude the admin check is the AuthorizedAdminSetting middleware; proven by tests/Unit/Controller/PackageControllerTest.php::testReloadIsAdminOnlyAndRefusesWithoutAUser

- **GIVEN** a request without a user, or from a user who is not an administrator
- **WHEN** it posts to `/api/packages/reload`
- **THEN** the installer SHALL not be called

### Requirement: The case type list exports its selection

The case type list SHALL allow its export and the `caseType` schema SHALL be
flagged exportable (`configuration.exportable`), so the Actions menu offers
"Export selection as JSON" for the selected case types.

#### Scenario: Two selected case types
@e2e exclude configuration only; proven by tests/Unit/Settings/CaseTypeListExportTest.php
- **GIVEN** two case types selected in the list
- **WHEN** an administrator chooses Export selection as JSON
- **THEN** one JSON file SHALL hold those two case types

