## ADDED Requirements

### Requirement: The prerequisites are declared once and shown (REQ-ADMIN-024)

dossiq SHALL declare its PHP version, PHP extensions, Nextcloud range,
required app and optional apps in one place, and the admin settings section
SHALL show each with present or missing, read live. `composer.json`,
`appinfo/info.xml` and the README Requirements table SHALL agree with the
declaration, proven by a unit test.

#### Scenario: A missing extension is named
@e2e exclude the check reads `extension_loaded()`; covered by PrerequisitesTest over an injected checker with `zip` reported absent

- **GIVEN** `ext-zip` is absent
- **WHEN** the admin opens the dossiq settings section
- **THEN** the Prerequisites block SHALL list zip as missing

#### Scenario: Optional apps say what they unlock
@e2e tests/e2e/admin-prerequisites.spec.ts

- **GIVEN** humaniq is not installed
- **WHEN** the admin opens the section
- **THEN** humaniq SHALL be listed as optional, missing, with what it unlocks

#### Scenario: The four sources agree
@e2e exclude structural; PrerequisitesTest::testSourcesAgree

- **GIVEN** the declaration
- **WHEN** `composer.json`, `info.xml` and the README table are parsed
- **THEN** each SHALL match the declaration
