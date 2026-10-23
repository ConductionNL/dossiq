# dossiq-store-surface Delta: advanced-holds-the-catalogue-folders

**Status**: draft

## MODIFIED Requirements

### Requirement: REQ-DSS-006 The menu entry names the concept

The store menu entry SHALL be labelled `Marketplace` (Dutch `Marktplaats`) and render the `StoreOutline`
icon, in the footer section between Documentation and Reports. The store page title SHALL read the same.
The route `/store` and the ids `Store` and `StoreMenu` SHALL stay, so existing links keep working.

#### Scenario: The entry carries the Tier A glyph
@e2e tests/e2e/spec-coverage/store-surface.spec.ts


- **GIVEN** the dossiq manifest
- **THEN** the entry labelled `Marketplace` MUST declare `icon: "StoreOutline"`
- **AND** it MUST sit in the `footer` section with an order between
  Documentation and Reports

#### Scenario: The Dutch menu
@e2e exclude The Playwright suite runs in English; tests/vitest/advancedHoldsCatalogueFolders.spec.js asserts the Dutch catalogue maps Marketplace to Marktplaats.


- **GIVEN** a user whose language is Dutch
- **WHEN** they read the footer of the navigation
- **THEN** the entry reads "Marktplaats", not "Winkel" or "Store"
