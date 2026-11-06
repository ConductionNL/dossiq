# case-types delta: besluitvorming-package

## ADDED Requirements

### Requirement: An administrator lists and installs packages through the API (REQ-CT-52)

The app SHALL answer `GET /api/packages` with every shipped package, its version, its install mode and the version installed here, and `POST /api/packages/{id}/install` by installing that package through the package installer. Both SHALL require an administrator. An install that is installed or already current SHALL answer 200; a package that is unknown, invalid or needs a capability this instance lacks SHALL answer 422 with the installer's reason; a write that failed and was taken back SHALL answer 500.

#### Scenario: An on-request package installs through the API
@e2e tests/e2e/packages/besluitvorming.spec.ts

- **GIVEN** an administrator and a shipped package marked `on-request`
- **WHEN** they post to `/api/packages/{id}/install`
- **THEN** the response SHALL be 200 with status `installed` or `current`

#### Scenario: A refused install says why
@e2e exclude no user interface; proven by tests/Unit/Controller/PackageControllerTest.php

- **GIVEN** a package id that is not shipped
- **WHEN** an administrator posts to `/api/packages/{id}/install`
- **THEN** the response SHALL be 422 with the installer's message
