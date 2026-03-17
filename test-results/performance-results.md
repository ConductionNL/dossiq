# procest — Performance Test Results

**Date:** 2026-03-13
**Perspective:** Performance
**Environment:** http://nextcloud.local
**Browser:** browser-4 (headless)
**Login:** admin

> Experimental agentic testing — results should be verified manually for critical findings.

## Summary

| Status | Count |
|--------|-------|
| PASS | 1 |
| PARTIAL | 3 |
| FAIL | 2 |
| CANNOT_TEST | 2 |

## Critical Finding: App is Not Configured

**All app views are non-functional** because `/apps/procest/api/settings` returns HTTP 404.

Root cause: The JavaScript code calls `/apps/procest/api/settings` but the Nextcloud route is registered under `/index.php/apps/procest/api/settings`. Apache's mod_rewrite is not stripping `index.php` for this path, causing a 404. Alternatively, the app may not be fully installed/activated. The admin settings page shows all schema fields (Register, Zaak schema, Taak schema, etc.) as **empty** — the app has never been configured.

Because `fetchSettings()` fails, `initializeStores()` never registers object types, so every subsequent call to fetch cases, tasks, statusTypes, and caseTypes fails with `"Object type not registered"`.

## Results by Page

### Dashboard
- **Status**: FAIL
- **Screenshot**: performance-dashboard.png
- **API Calls** (measured via Performance API, page load):
  | Endpoint | Method | Status | Time |
  |----------|--------|--------|------|
  | /apps-extra/procest/l10n/nl.json | XHR GET | 200 | 3ms |
  | /ocs/v2.php/apps/user_status/api/v1/heartbeat | XHR PUT | 200 | 135ms |
  | /ocs/v2.php/apps/user_status/api/v1/user_status | XHR GET | 200 | 131ms |
  | /index.php/contactsmenu/teams | XHR GET | 200 | 133ms |
  | /apps/procest/api/settings | fetch GET | **404** | 4ms |
- **Notes**: Dashboard content area is completely blank. Four console errors: `Error fetching case collection`, `Error fetching caseType collection`, `Error fetching statusType collection`, `Error fetching task collection` — all caused by the settings 404. The Nextcloud system calls (heartbeat, user_status, contactsmenu) are all FAST (108–135ms).

### Case List View (Zaken)
- **Status**: FAIL
- **Screenshot**: performance-case-list.png
- **API Calls** (same page load, identical set):
  | Endpoint | Method | Status | Time |
  |----------|--------|--------|------|
  | /apps-extra/procest/l10n/nl.json | XHR GET | 200 | 2ms |
  | /ocs/v2.php/apps/user_status/api/v1/heartbeat | XHR PUT | 200 | 114ms |
  | /ocs/v2.php/apps/user_status/api/v1/user_status | XHR GET | 200 | 110ms |
  | /index.php/contactsmenu/teams | XHR GET | 200 | 108ms |
  | /apps/procest/api/settings | fetch GET | **404** | 4ms |
- **Pagination**: CANNOT_TEST — no cases exist
- **Notes**: Shows "No items found". UI shell renders (Cards/Table toggle, Add Item, Actions buttons). Errors: `Error fetching caseType collection`, `Error fetching statusType collection`. No search box visible in the list toolbar.

### Case Detail View
- **Status**: CANNOT_TEST
- **Reason**: No cases exist in the system (empty database + settings not configured). Cannot navigate to any case detail.

### Task List View (Taken)
- **Status**: FAIL
- **Screenshot**: performance-task-list.png
- **API Calls**: Same as Case List (single page load, SPA hash navigation makes no additional network calls)
  | Endpoint | Method | Status | Time |
  |----------|--------|--------|------|
  | /apps/procest/api/settings | fetch GET | **404** | 4ms |
- **Pagination**: CANNOT_TEST — no tasks exist
- **Notes**: Shows "No items found". UI shell (Cards/Table toggle, Add Item, Actions) renders correctly.

### My Work View (Mijn werk)
- **Status**: PARTIAL
- **Screenshot**: performance-my-work.png
- **API Calls**: SPA hash navigation, no new network requests after initial page load.
- **Notes**: This is the only view that renders meaningful content despite the broken settings. Shows "Mijn werk (0)" with tab buttons for Alles/Zaken/Taken and "Geen items aan u toegewezen" message. One error: `Error fetching case collection: Error: Object type "case" is not registered` and warning `Case object type not registered`. The empty-state UI is correct and functions.

### Admin Settings
- **Status**: PARTIAL
- **Screenshot**: performance-admin.png
- **API Calls** (full page reload):
  | Endpoint | Method | Status | Time |
  |----------|--------|--------|------|
  | /apps-extra/procest/l10n/nl.json | XHR GET | 200 | 2ms |
  | /ocs/v2.php/apps/user_status/api/v1/heartbeat | XHR PUT | 200 | 132ms |
  | /ocs/v2.php/apps/user_status/api/v1/user_status | XHR GET | 200 | 108ms |
  | /index.php/contactsmenu/teams | XHR GET | 200 | 110ms |
  | /apps/procest/api/settings | fetch GET | **404** | 4ms |
  | /apps/procest/api/settings | fetch GET | **404** | 4ms |
  | /apps/procest/api/zgw-mappings | fetch GET | **404** | 8ms |
- **Notes**: The admin page renders its form (Register/schema fields all empty). The ZGW API Mapping table renders with 11 rows all showing "Not configured". Two calls to `/apps/procest/api/settings` (one from main app init, one from settings page init). The 404s return instantly (4–8ms) so there is no timeout delay. The "Zaaktypebeheer" section shows "No items found".

### Search/Filter Test
- **Status**: CANNOT_TEST
- **Reason**: No search box is present in the case list toolbar (only Cards/Table toggle, Add Item, Actions). No data exists to search through. The Actions dropdown was not explored further.

## Performance Summary

| Page | Slowest Call | Time | Status |
|------|-------------|------|--------|
| Dashboard | /ocs/v2.php/.../heartbeat | 135ms | FAST (page broken) |
| Case List | /ocs/v2.php/.../heartbeat | 114ms | FAST (page broken) |
| Case Detail | N/A | N/A | CANNOT_TEST |
| Task List | /ocs/v2.php/.../heartbeat | 114ms | FAST (page broken) |
| My Work | (no new requests) | N/A | PARTIAL |
| Admin | /ocs/v2.php/.../heartbeat | 132ms | FAST (features broken) |

**Note**: All Nextcloud system calls (heartbeat, user_status, contactsmenu/teams) consistently respond in **108–135ms (FAST)**. The app-specific API calls all return 404 in 4–8ms (instantly). There are no SLOW or PERFORMANCE_FAIL network calls — the performance problem is functional (broken API routes), not latency.

## Pagination

- Case List: CANNOT_TEST (no data)
- Task List: CANNOT_TEST (no data)

## Console Errors Summary

Unique errors observed across all pages:

1. `Error fetching Procest settings: Error: Failed to fetch settings: Not Found` — all pages
2. `Error fetching case collection: Error: Object type "case" is not registered` — Dashboard, My Work, Case List
3. `Error fetching caseType collection: Error: Object type "caseType" is not registered` — Dashboard, Case List, Admin
4. `Error fetching statusType collection: Error: Object type "statusType" is not registered` — Dashboard, Case List
5. `Error fetching task collection: Error: Object type "task" is not registered` — Dashboard
6. `Error fetching ZGW mappings: Error: Failed to fetch ZGW mappings` — Admin settings
7. `Refused to apply style from '...procest...'` — CSS loading error on page load (CSP/MIME issue)
8. `Case object type not registered` (warning) — My Work

## Recommendations

1. **CRITICAL — Fix `/apps/procest/api/settings` URL**: The JS hardcodes `/apps/procest/api/settings` without `index.php`. Either configure Apache to rewrite this path, or use `OC.generateUrl('/apps/procest/api/settings')` which produces the correct `/index.php/apps/procest/api/settings`. This single fix would unblock the entire app.

2. **CRITICAL — App requires initial configuration**: After fixing the URL, an admin must configure the app by setting Register and all schema IDs in the admin settings. Consider auto-configuring on first install via the existing `InitializeSettings` repair step.

3. **CSS loading errors**: `Refused to apply style` errors on page load indicate a Content Security Policy or MIME type issue with the app's stylesheet. Investigate the stylesheet URL and Nextcloud CSP headers.

4. **Double settings fetch on admin page**: The admin settings page calls `/apps/procest/api/settings` twice (24ms apart). Both the main `initializeStores()` and the settings page component each call `fetchSettings()` independently. Consider deduplicating or caching the in-flight request.

5. **No search/filter UI in case list**: No search box is present in the case list toolbar. If search is a planned feature, it should be added. If it exists inside the Actions dropdown, it should be made more discoverable.

6. **Error handling UX**: When settings cannot be loaded, the Dashboard shows a completely blank content area with no error message. Consider showing a user-friendly "Configuration required — please contact your administrator" message instead of rendering nothing.

7. **Performance baseline (when functional)**: Once the settings URL is fixed and the app is configured, actual API call performance to OpenRegister should be measured. Current testing could not establish a performance baseline for case/task list, detail, or search operations.
