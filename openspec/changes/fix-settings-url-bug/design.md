## Approach

Replace hardcoded API URL strings in Pinia stores with `generateUrl()` from `@nextcloud/router`. This function is already a dependency of the project and produces the correct Nextcloud-routed path (e.g., `/index.php/apps/procest/api/settings` or `/apps/procest/api/settings` depending on the server's URL rewrite config). It is the standard pattern used by all other Nextcloud apps.

No PHP changes are needed. The backend routes in `lib/AppInfo/Application.php` are correctly registered. The bug is purely on the client side.

## Implementation

### Import

Add the import to the top of each affected store file:

```js
import { generateUrl } from '@nextcloud/router'
```

### URL Replacements

**`src/store/modules/settings.js`** — 2 occurrences:

```js
// BEFORE
fetch('/apps/procest/api/settings', ...)

// AFTER
fetch(generateUrl('/apps/procest/api/settings'), ...)
```

Both the `fetchSettings()` action (GET) and the `saveSettings()` action (POST) use this URL.

**`src/store/modules/zgwMapping.js`** — 3 occurrences:

```js
// BEFORE
fetch('/apps/procest/api/zgw-mappings', ...)
fetch(`/apps/procest/api/zgw-mappings/${resourceKey}`, ...)
fetch(`/apps/procest/api/zgw-mappings/${resourceKey}/reset`, ...)

// AFTER
fetch(generateUrl('/apps/procest/api/zgw-mappings'), ...)
fetch(generateUrl(`/apps/procest/api/zgw-mappings/${resourceKey}`), ...)
fetch(generateUrl(`/apps/procest/api/zgw-mappings/${resourceKey}/reset`), ...)
```

### Why `@nextcloud/router` and not hardcoding `/index.php/`

Hardcoding `/index.php/` would fix the immediate issue but would break in environments where Nextcloud's Apache/nginx mod_rewrite is configured to strip `index.php` (the default in most production setups). `generateUrl()` always produces the correct path for the current server configuration.

## Files Affected

| File | Change |
|------|--------|
| `src/store/modules/settings.js` | Add import + fix 2 fetch URLs |
| `src/store/modules/zgwMapping.js` | Add import + fix 3 fetch URLs |

## Testing

After the fix, verify:
1. Browser console shows no 404 on `/apps/procest/api/settings` at page load
2. Dashboard renders KPI cards and data (not a blank screen)
3. Case list and task list show data (not just "No items found")
4. Admin settings form shows the current register/schema configuration (not empty fields)
5. ZGW mapping table loads correctly on the admin settings page
