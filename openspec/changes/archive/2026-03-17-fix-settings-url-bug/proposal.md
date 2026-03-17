## Why

The Procest frontend calls internal API endpoints using hardcoded paths (e.g., `/apps/procest/api/settings`) that bypass Nextcloud's URL routing, causing HTTP 404 errors on every page load. This single bug cascades into complete app failure: all Pinia object stores fail to register their types, making every data fetch (cases, tasks, case types, statuses, ZGW mappings) throw "Object type X is not registered". The app is functionally broken for all users in all environments. Identified by automated browser testing across 5 test perspectives.

## What Changes

- Replace all hardcoded `/apps/procest/api/...` paths in Pinia stores with `generateUrl('/apps/procest/api/...')` from `@nextcloud/router`
- Affected stores: `settings.js` (2 occurrences) and `zgwMapping.js` (3 occurrences)
- No PHP changes required — the backend routes are correctly registered; only the frontend URL construction is broken

## Capabilities

### New Capabilities

_(none — this is a bug fix)_

### Modified Capabilities

- `openregister-integration`: The requirement for how frontend stores construct API URLs is being tightened. All internal Procest API calls MUST use `generateUrl()` from `@nextcloud/router` to produce correct Nextcloud-routed paths. Hardcoded paths are prohibited.

## Impact

- **Fixes**: All 5 hardcoded fetch URLs in `src/store/modules/settings.js` and `src/store/modules/zgwMapping.js`
- **Unblocks**: Dashboard KPI cards, case list, task list, admin settings form — everything that depends on `initializeStores()` succeeding
- **No breaking changes**: The API contracts are unchanged; only the client-side URL construction changes
- **No PHP changes**: `SettingsController` and `ZgwMappingController` routes are correctly defined
