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
