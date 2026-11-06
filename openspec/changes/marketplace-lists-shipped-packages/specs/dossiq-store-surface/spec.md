## ADDED Requirements

### Requirement: The marketplace lists the shipped packages
`GET /api/store/packages` SHALL return every valid package that has a summary, with its version, installed version and whether it installs on request, to any signed-in user. It SHALL hold no case data.

#### Scenario: An employee browses
- GIVEN a signed-in user who is no administrator
- WHEN the marketplace opens without a registry
- THEN the shipped packages are listed

### Requirement: An administrator installs a shipped package
`POST /api/store/packages/{id}/install` SHALL be refused to anyone who is no administrator before anything is written. It SHALL refuse a malformed id with 400, a package that is not on request with 404, and a package the installer refuses with 422.

#### Scenario: A package that installs automatically
- GIVEN a package with `install: auto`
- WHEN an administrator posts its install
- THEN the answer is 404 and nothing is written
