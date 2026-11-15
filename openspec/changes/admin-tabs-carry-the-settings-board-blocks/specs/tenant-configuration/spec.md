# tenant-configuration Delta: admin-tabs-carry-the-settings-board-blocks

**Status**: draft

## ADDED Requirements

### Requirement: General MUST carry the time zone, the date format and the new case page switch

The General tab's Texts and access card SHALL carry Time zone (default Europe/Amsterdam), Date format (DD-MM-YYYY, YYYY-MM-DD or D MMMM YYYY, default DD-MM-YYYY) and, under Beta features, the switch `feature_new_case_page`: "New case page visible to everyone at once, without waiting for the cache". The switch SHALL take effect on the next page load for every user, without a cache-invalidation wait.

#### Scenario: Switching on the new case page
@e2e exclude Needs the backend key; tests/vitest/settingsBoardBlocks.spec.js asserts the field.

- **GIVEN** an administrator switches on the new case page under Beta features
- **WHEN** any user opens a case afterwards
- **THEN** the new case page shows, with no wait for a cache to expire
