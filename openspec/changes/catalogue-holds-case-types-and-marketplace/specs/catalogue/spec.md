# catalogue Delta: catalogue-holds-case-types-and-marketplace

**Status**: draft
**Scope**: where the case types sit in the navigation (boards DqCatalogus, DqZaaktypen).

## ADDED Requirements

### Requirement: Case types MUST be reached through the catalogue by whoever has the catalogue

The menu entry `CaseTypesMenu` SHALL be hidden for an administrator, in both the simple and the full structure, because the Catalogue entry (admin-only) leads to the same page through its Case types folder. For a user who is not an administrator the entry SHALL stay, because that user has no catalogue. The Case types page and its route `/settings/case-types` SHALL stay unchanged, so existing links, saved views and the tour keep working.

#### Scenario: An administrator opens Advanced
@e2e tests/e2e/app-chrome.spec.ts


- **GIVEN** an administrator, in either menu structure
- **WHEN** they open the Advanced foldout
- **THEN** they see Catalogue and no separate Case types entry
- **AND** the catalogue's Case types folder opens `/settings/case-types`

#### Scenario: A user who is not an administrator opens Advanced
@e2e exclude Navigation built from the manifest per account; the Playwright suite signs in as an administrator only, so tests/vitest/catalogueHoldsCaseTypes.spec.js builds both menus with the library's buildManifest and visibleIf evaluator for both kinds of account.


- **GIVEN** a user who is not an administrator
- **WHEN** they open the Advanced foldout
- **THEN** they still see Case types, and no Catalogue

#### Scenario: An old link to the case types
@e2e exclude Nothing about the route changes; tests/vitest/catalogueHoldsCaseTypes.spec.js asserts the Case types page keeps /settings/case-types in both structures.


- **GIVEN** a bookmark to `/apps/dossiq/settings/case-types?category=VTH`
- **WHEN** anyone opens it
- **THEN** the Case types page opens on that folder, as before
