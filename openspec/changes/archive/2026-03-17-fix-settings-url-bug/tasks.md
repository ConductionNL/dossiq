# Tasks: fix-settings-url-bug

## Task 1: Fix settings.js fetch URLs

**File**: `src/store/modules/settings.js`

**What**:
1. Add `import { generateUrl } from '@nextcloud/router'` at the top of the file
2. In `fetchSettings()`: replace `fetch('/apps/procest/api/settings', ...)` with `fetch(generateUrl('/apps/procest/api/settings'), ...)`
3. In `saveSettings()`: replace `fetch('/apps/procest/api/settings', ...)` with `fetch(generateUrl('/apps/procest/api/settings'), ...)`

**Spec ref**: `openspec/changes/fix-settings-url-bug/specs/openregister-integration/spec.md#REQ-OREG-014`

**Acceptance criteria**:
- [x] `@nextcloud/router` imported at top of file
- [x] Both fetch calls in `settings.js` use `generateUrl()`
- [x] No hardcoded `/apps/procest/api/settings` strings remain in the file

---

## Task 2: Fix zgwMapping.js fetch URLs

**File**: `src/store/modules/zgwMapping.js`

**What**:
1. Add `import { generateUrl } from '@nextcloud/router'` at the top of the file
2. Fix the 3 fetch calls:
   - List all mappings: `fetch('/apps/procest/api/zgw-mappings', ...)` → `fetch(generateUrl('/apps/procest/api/zgw-mappings'), ...)`
   - Get/update single mapping: `fetch('/apps/procest/api/zgw-mappings/${resourceKey}', ...)` → `fetch(generateUrl('/apps/procest/api/zgw-mappings/${resourceKey}'), ...)`
   - Reset mapping: `fetch('/apps/procest/api/zgw-mappings/${resourceKey}/reset', ...)` → `fetch(generateUrl('/apps/procest/api/zgw-mappings/${resourceKey}/reset'), ...)`

**Spec ref**: `openspec/changes/fix-settings-url-bug/specs/openregister-integration/spec.md#REQ-OREG-014`

**Acceptance criteria**:
- [x] `@nextcloud/router` imported at top of file
- [x] All 3 fetch calls in `zgwMapping.js` use `generateUrl()`
- [x] No hardcoded `/apps/procest/api/zgw-mappings` strings remain in the file

---

## Task 3: Verify fix works end-to-end

**What**: After applying Tasks 1 and 2, verify the fix resolves the cascade failure.

**Acceptance criteria**:
- [x] Browser console shows no 404 on `/apps/procest/api/settings` on page load
- [x] Browser console shows no "Object type X is not registered" errors
- [x] Dashboard renders (KPI cards visible, not blank screen)
- [x] Case list shows data or meaningful empty state (not "No items found" due to API failure)
- [x] Admin settings form shows register/schema IDs (not empty fields)
- [x] ZGW mapping table loads on admin settings page

> **Note**: Tasks 1 and 2 were already implemented before this change was spec'd. Verification (Task 3) requires a running Nextcloud instance.
