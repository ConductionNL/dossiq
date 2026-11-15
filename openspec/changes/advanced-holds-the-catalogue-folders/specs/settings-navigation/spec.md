# settings-navigation Delta: advanced-holds-the-catalogue-folders

**Status**: draft
**Scope**: where case types, attributes, templates and object types sit in the navigation.

## ADDED Requirements

### Requirement: The catalogue's folders MUST be entries under Advanced, case types first

dossiq SHALL NOT offer a Catalogue menu entry or page. In both menu structures the Advanced foldout SHALL carry Case types, Attributes, Templates and Object types, in that order and before every other entry of the foldout. Case types, Attributes and Templates SHALL be shown to every user; Object types SHALL be admin-only, for the entry and for the page.

#### Scenario: An administrator opens Advanced
@e2e tests/e2e/app-chrome.spec.ts

- **GIVEN** an administrator, in either menu structure
- **WHEN** they open the Advanced foldout
- **THEN** it starts with Case types, Attributes, Templates, Object types
- **AND** there is no Catalogue entry

#### Scenario: A user who is not an administrator opens Advanced
@e2e exclude The Playwright suite signs in as an administrator only; tests/vitest/advancedHoldsCatalogueFolders.spec.js builds both menus with the library's buildManifest for both kinds of account.

- **GIVEN** a user who is not an administrator
- **WHEN** they open the Advanced foldout
- **THEN** they see Case types, Attributes and Templates, and no Object types

### Requirement: The Object types page MUST keep what only the catalogue offered

The page `ObjectTypes` at `/settings/object-types` SHALL list the object types cases link from `/api/catalogue/object-types`, four per page, with Download as CSV, New object type and Add object type leading to OpenRegister's schemas.

#### Scenario: An administrator opens Object types
@e2e exclude Page configuration only; tests/vitest/cataloguePage.spec.js asserts the table, its endpoint, pager and actions.

- **WHEN** an administrator opens Advanced, Object types
- **THEN** the object types table, Download and New object type are there, as on the catalogue before

### Requirement: An old catalogue link MUST land on the matching entry

`/settings/catalogue` SHALL redirect: with a `category` query to the Case types page with that query, otherwise to the Object types page.

#### Scenario: A bookmark to a case type folder of the catalogue
@e2e exclude A client-side router redirect; tests/vitest/advancedHoldsCatalogueFolders.spec.js resolves it through the route table.

- **GIVEN** a bookmark `/settings/catalogue?category=VTH`
- **WHEN** it is opened
- **THEN** the Case types page opens filtered on VTH

#### Scenario: A bookmark to the catalogue
@e2e exclude A client-side router redirect; tests/vitest/advancedHoldsCatalogueFolders.spec.js resolves it through the route table.

- **GIVEN** a bookmark `/settings/catalogue`
- **WHEN** it is opened
- **THEN** the Object types page opens
