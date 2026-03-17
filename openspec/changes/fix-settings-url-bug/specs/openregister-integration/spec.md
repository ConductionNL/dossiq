# Delta Spec: openregister-integration

## Change: fix-settings-url-bug

### MODIFIED Requirements

#### REQ-OR-URL-01: Frontend API URL Construction

**Status**: MODIFIED (was implicit/unspecified, now explicit)

**Old behaviour**: Internal Procest API URLs were constructed as hardcoded string literals (e.g., `'/apps/procest/api/settings'`), which fail on Nextcloud installations where `index.php` is not rewritten.

**New requirement**: All frontend `fetch()` calls to internal Procest API endpoints MUST construct URLs using `generateUrl()` from `@nextcloud/router`:

```js
import { generateUrl } from '@nextcloud/router'

// Correct
fetch(generateUrl('/apps/procest/api/settings'), { ... })
fetch(generateUrl(`/apps/procest/api/zgw-mappings/${key}`), { ... })

// Prohibited
fetch('/apps/procest/api/settings', { ... })
fetch(`/apps/procest/api/zgw-mappings/${key}`, { ... })
```

**Rationale**: `generateUrl()` produces the correct URL for the current Nextcloud server configuration regardless of whether `mod_rewrite` / `index.php` stripping is enabled. Hardcoded paths produce 404 errors on standard Nextcloud installations.

**Scope**: This requirement applies to ALL Pinia stores making calls to Procest's own backend controllers (`SettingsController`, `ZgwMappingController`). It does NOT apply to calls to OpenRegister's API (`/index.php/apps/openregister/api/...`), which are handled separately.

**Acceptance criteria**:
- GIVEN a Procest app page load WHEN `fetchSettings()` is called THEN the network request goes to the correct URL (no 404)
- GIVEN a Procest admin settings page WHEN ZGW mappings are fetched THEN the network request goes to the correct URL (no 404)
- GIVEN the fix is applied WHEN the app initializes THEN `initializeStores()` completes without "Object type X is not registered" errors
