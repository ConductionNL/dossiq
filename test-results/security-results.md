# Procest — Security Test Results

**Date:** 2026-03-13 (re-run; original: 2026-03-12)
**Perspective:** Security
**Environment:** http://nextcloud.local
**Browser:** browser-7 (headless)
**Login:** admin

> Experimental agentic testing — results should be verified manually for critical findings.
> This testing was performed in an authorized development environment.
> Re-run on 2026-03-13 confirmed all prior findings still present and added updated screenshots.

---

## Summary

| Status | Count |
|--------|-------|
| PASS | 7 |
| PARTIAL | 1 |
| FAIL | 3 |
| CANNOT_TEST | 1 |
| INFO | 4 |

---

## Results by Test Category

### XSS Testing

**Status: PASS (no XSS execution observed)**

Payloads tested in form fields (Zaken Add dialog — Titel and Omschrijving; Admin Settings — Register field):

| Page | Field | Payload | Result |
|------|-------|---------|--------|
| Zaken `#/cases` — Add dialog | Titel | `<script>alert('xss')</script>` | Rendered as literal text, no execution |
| Zaken `#/cases` — Add dialog | Omschrijving | `<img src=x onerror=alert(1)>` | Rendered as literal text, no execution |
| Admin Settings | Register | `<script>alert("stored-xss")</script>` | Accepted by API; JSON-encoded in response (`\u003Cscript\u003E`); not rendered in form (settings 404 bug prevents reload) |

Vue's reactive data binding handles input values as data, not HTML — `v-model` and `{{ }}` escape content by default. No `alert()` fired. No `<script>` or `<img onerror>` elements injected into the DOM.

**PARTIAL caveat:** Because the settings API 404 bug prevents the admin settings form from loading stored values back from the server, a full stored-XSS test cycle (write → reload → verify rendering) could not be completed. The JSON response from `POST /index.php/apps/procest/api/settings` showed correct HTML-entity encoding (`\u003Cscript\u003E`), suggesting backend output is safe, but the Vue rendering of stored settings values in form fields was not verified.

**Screenshots:** `security-xss-cases-form.png` (XSS payloads as plain text in Zaken form)

---

### Console / Data Leakage

**Status: PASS (no sensitive data in console)**

Console errors observed across all pages:

| Error | Severity | Notes |
|-------|----------|-------|
| `Refused to apply style from .../profiler/css/profiler-toolbar.css` (MIME mismatch) | Low | Debug profiler app active — dev env artifact |
| `Failed to load resource: 404 @ /apps/procest/api/settings` | Medium | JS URL path bug (missing `/index.php/`) |
| `Error fetching Procest settings: Error: Failed to fetch settings: Not Found` | Medium | Cascade from above |
| `Error: Object type "case" is not registered in the store` (×4 types) | Low | Cascade from settings 404 |
| `Failed to load resource: 404 @ /apps/procest/api/zgw-mappings` | Medium | JS URL path bug |
| `Error fetching ZGW mappings: Error: Failed to fetch ZGW mappings: Not Found` | Low | Cascade from above |

Stack traces in console reference only internal JS bundle line numbers — no user data, tokens, passwords, or credentials appear in any error.

**localStorage:** Only `nextcloud_per_bmV4dGNsb3Vk_user-has-avatar.admin: "true"` — no sensitive data.
**sessionStorage:** Empty.

---

### CSRF Protection

**Status: PASS**

- Unauthenticated POST to `/index.php/apps/procest/api/settings` without CSRF token returns **HTTP 412 Precondition Failed** (Nextcloud's CSRF check).
- Unauthenticated GET to same endpoint also returns **HTTP 412**.
- With a valid `requesttoken` header (Nextcloud CSRF token from `OC.requestToken`), requests succeed as expected.
- ZGW API endpoints (`@NoCSRFRequired` + `@PublicPage`) bypass CSRF checks intentionally — they use JWT auth instead. This is correct for M2M APIs.

---

### Authentication Boundaries

**Status: FAIL — ZRC Index Endpoint Missing JWT Validation**

#### Critical Finding: ZrcController::index() Bypasses JWT Auth

**File:** `/home/wilco/nextcloud-docker-dev/workspace/server/apps-extra/procest/lib/Controller/ZrcController.php`, line 107–117

The `GET /index.php/apps/procest/api/zgw/zaken/v1/zaken` endpoint (and all other ZRC list resources) calls `zgwService->handleIndex()` **without first calling `validateJwtAuth()`**. All other ZRC write methods (`create`, `update`, `patch`, `destroy`) correctly call `validateJwtAuth()` before proceeding.

Verified by sending `GET /index.php/apps/procest/api/zgw/zaken/v1/zaken` with `Authorization: Bearer invalid.token.here` while unauthenticated — response was **HTTP 200** with `{"count":0,"next":null,"previous":null,"results":[]}`.

Other ZGW controllers (ZtcController, NrcController, etc.) properly return HTTP 503 "Authorization service not available" because their `index()` methods call `validateJwtAuth()`.

**Impact:** Any unauthenticated party can list all zaken (and other ZRC resources such as statussen, resultaten, rollen) without a valid JWT. In a populated environment this would expose case/zaak data to unauthenticated external callers.

**Affected endpoint pattern:** `GET /index.php/apps/procest/api/zgw/zaken/v1/{resource}`

---

#### ZgwAuthMiddleware Dead Code / Scope Enforcement Inactive

**File:** `/home/wilco/nextcloud-docker-dev/workspace/server/apps-extra/procest/lib/Middleware/ZgwAuthMiddleware.php`, line 150

```php
if (($controller instanceof ZgwController) === false) {
    return;
}
```

The middleware guards only controllers that are `instanceof ZgwController`, but no `ZgwController` base class exists — all ZGW controllers extend `OCP\AppFramework\Controller` directly. The `beforeController()` check therefore always short-circuits and the middleware's scope enforcement (`enforceScopes()`) never runs.

**Impact:** ZGW component-level scope authorization (e.g., `zrc.lezen`, `ztc.aanmaken`) is never enforced. Any valid JWT consumer can call any ZGW endpoint regardless of configured scopes in OpenRegister's Consumer authorization configuration. The confidentiality level filtering in the middleware (`isConfidentialityAllowed()`) is also dead code.

Auth is still partially enforced because controllers call `validateJwtAuth()` inline, but scope-based access control is completely inactive.

---

#### Nextcloud App Authentication

**Status: PASS**

- Unauthenticated navigation to `http://nextcloud.local/index.php/apps/procest` correctly redirects to the Nextcloud login page.
- No app content accessible without a valid Nextcloud session.

---

#### Admin Settings Access Control

**Status: PASS**

- `SettingsController::create()` (POST `/api/settings`) — no `@NoAdminRequired`, requires admin.
- `SettingsController::index()` (GET `/api/settings`) — has `@NoAdminRequired`; any authenticated user can read schema/register IDs. These are internal configuration integers with no direct data-access capability — acceptable low risk.
- `ZgwMappingController` methods have no `@NoAdminRequired` — admin only.
- Admin settings page (`/index.php/settings/admin/procest`) protected by Nextcloud's admin panel.

---

### Security Headers

**Status: PASS**

Headers observed on API responses:

| Header | Value | Assessment |
|--------|-------|------------|
| `content-security-policy` | `default-src 'none';base-uri 'none';manifest-src 'self';frame-ancestors 'none'` | Strong CSP |
| `x-frame-options` | `SAMEORIGIN` | Present |
| `x-content-type-options` | `nosniff` | Present |
| `referrer-policy` | `no-referrer` | Present |
| `feature-policy` | `autoplay 'none';camera 'none';...` | Present |
| `x-permitted-cross-domain-policies` | `none` | Present |
| `x-robots-tag` | `noindex, nofollow` | Present |
| `x-xss-protection` | Not present | N/A — superseded by CSP |

**Information-leaking headers (low risk in dev env):**

| Header | Value | Notes |
|--------|-------|-------|
| `x-powered-by` | `PHP/8.4.18` | Exposes PHP version — should be removed in production |
| `x-debug-token` | e.g. `jBTvqNWh53nOA5k5Au8C` | Mirrors `x-request-id` — dev artifact, no secrets |
| `x-user-id` | `admin` | Exposes current username in all responses — Nextcloud standard, but should be reviewed for public API responses |
| `server` | `nginx` | Exposes web server type |

The `x-powered-by` and `server` headers are standard Nextcloud/nginx defaults. The `x-user-id` header is set by Nextcloud core on all authenticated responses. None of these are procest-specific.

---

## Console Errors Summary

Total across all pages tested: ~14–15 per page load

- **8 errors** per page: 2× CSS MIME (profiler), 2× settings 404 + error, 1× caseType not registered, 1× zgw-mappings 404 + error
- **2 warnings** per page: `@nextcloud/vue` button component missing label
- No sensitive data in any console message

---

## Network Requests Summary

| URL | Method | Status | Notes |
|-----|--------|--------|-------|
| `/apps/procest/api/settings` | GET | 404 | JS bug — wrong path |
| `/apps/procest/api/zgw-mappings` | GET | 404 | JS bug — wrong path |
| `/index.php/apps/procest/api/settings` | GET | 412 (no CSRF) / 200 (with CSRF) | Correct path, CSRF-protected |
| `/index.php/apps/procest/api/settings` | POST | 200 | Settings write — admin only |
| `/index.php/apps/procest/api/zgw/zaken/v1/zaken` | GET | 200 | **No JWT required** — auth bypass (critical) |
| `/index.php/apps/procest/api/zgw/catalogi/v1/zaaktypen` | GET | 503 | JWT required — auth service unavailable but checked |
| `/index.php/apps/procest/api/zgw/notificaties/v1/kanalen` | GET | 401 | JWT required correctly |
| `/ocs/v2.php/apps/user_status/api/v1/heartbeat` | PUT | 200 | Standard Nextcloud |
| `/index.php/contactsmenu/teams` | GET | 200 | Standard Nextcloud |

External domains: none observed. All requests go to `nextcloud.local` only.

---

## Key Findings Summary

| # | Finding | Severity | Status |
|---|---------|----------|--------|
| 1 | `ZrcController::index()` missing `validateJwtAuth()` — unauthenticated access to all ZRC list endpoints | **Critical** | FAIL |
| 2 | `ZgwAuthMiddleware` scope enforcement is dead code — never runs for any controller | **High** | FAIL |
| 3 | Settings API URL path bug (`/apps/` vs `/index.php/apps/`) causes 404 on every page load | **Medium** (functional + security init) | FAIL |
| 4 | `x-powered-by: PHP/8.4.18` header exposed in all responses | Low | INFO |
| 5 | `x-user-id: admin` header exposed in all responses | Low | INFO |
| 6 | Profiler/debug app active in environment | Low | INFO |
| 7 | Settings GET readable by non-admin users (`@NoAdminRequired`) | Low | INFO |

---

## Screenshots

### 2026-03-13 Re-run
- `security-login-complete.png` — Post-login dashboard state
- `security-dashboard.png` — Dashboard (blank due to unconfigured OpenRegister backend)
- `security-case-list.png` — Cases list page ("No items found")
- `security-new-case-xss-input.png` — XSS payloads (`<script>alert('xss-test')</script>` and `<img src=x onerror="console.log('xss-img')">`) visible as plain text in the New Case form, form-level validation error shown
- `security-new-case-xss-display.png` — Same form after attempted submission, showing "Zaaktype is verplicht" validation, XSS payload still rendered as literal text
- `security-admin.png` — Admin settings page (`/index.php/settings/admin/procest`) showing configuration fields, ZGW mapping table, and case type management

### Prior Run (2026-03-12)
- `security-xss-cases-form.png` — XSS payloads rendered as plain text in Zaken Add dialog
- `security-tasks-xss-empty-form.png` — Tasks Create dialog (empty form)
- `security-admin-settings-full.png` — Admin settings page full view
- `security-login.png`, `security-xss-input.png`, `security-tasks-dialog.png`, `security-admin-settings.png`, `security-network-requests.png`
