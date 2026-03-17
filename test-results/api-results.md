# procest — API Test Results

**Date:** 2026-03-13
**Perspective:** API
**Environment:** http://nextcloud.local (port 80)
**Browser:** browser-1 (headless)
**Login:** admin / admin

> Experimental agentic testing — results should be verified manually for critical findings.

## Discovered API Endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/index.php/apps/procest/api/settings` | GET | Fetch app settings (register + schema IDs) |
| `/index.php/apps/procest/api/settings` | POST | Update app settings |
| `/index.php/apps/procest/api/settings/load` | POST | Force re-import configuration from procest_register.json |
| `/index.php/apps/procest/api/zgw-mappings` | GET | List all ZGW property mappings |
| `/index.php/apps/procest/api/zgw-mappings/{resourceKey}` | GET | Get single ZGW mapping |
| `/index.php/apps/procest/api/zgw-mappings/{resourceKey}` | PUT | Update ZGW mapping |
| `/index.php/apps/procest/api/zgw-mappings/{resourceKey}` | DELETE | Delete ZGW mapping |
| `/index.php/apps/procest/api/zgw-mappings/{resourceKey}/reset` | POST | Reset ZGW mapping to defaults |
| `/index.php/apps/procest/api/zgw/zaken/v1/zaken` | GET | List zaken (ZGW format) — public |
| `/index.php/apps/procest/api/zgw/zaken/v1/zaken` | POST | Create zaak — requires JWT |
| `/index.php/apps/procest/api/zgw/zaken/v1/zaken/_zoek` | POST | Search zaken — public |
| `/index.php/apps/procest/api/zgw/zaken/v1/rollen` | GET | List rollen — public |
| `/index.php/apps/procest/api/zgw/zaken/v1/statussen` | GET | List statussen — public |
| `/index.php/apps/procest/api/zgw/zaken/v1/zaken/{uuid}/zaakeigenschappen` | GET | List zaakeigenschappen — public |
| `/index.php/apps/procest/api/zgw/zaken/v1/{resource}/{uuid}/audittrail` | GET | Get audit trail — public |
| `/index.php/apps/procest/api/zgw/catalogi/v1/zaaktypen` | GET | List zaaktypen — requires JWT |
| `/index.php/apps/procest/api/zgw/besluiten/v1/besluiten` | GET | List besluiten — requires JWT |
| `/index.php/apps/procest/api/zgw/documenten/v1/enkelvoudiginformatieobjecten` | GET | List documenten — requires JWT |
| `/index.php/apps/procest/api/zgw/autorisaties/v1/applicaties` | GET | List applicaties — requires JWT |
| `/index.php/apps/procest/api/zgw/notificaties/v1/kanalen` | GET | List kanalen — requires JWT |
| `/index.php/apps/openregister/api/objects/2/16` | GET/POST | Cases (OpenRegister direct) |
| `/index.php/apps/openregister/api/objects/2/16/{uuid}` | GET/PUT/PATCH/DELETE | Single case |
| `/index.php/apps/openregister/api/objects/2/17` | GET/POST | Tasks (OpenRegister direct) |
| `/index.php/apps/openregister/api/objects/2/17/{uuid}` | DELETE | Single task |
| `/index.php/apps/openregister/api/objects/2/9` | GET/POST | Case types (OpenRegister direct) |
| `/index.php/apps/openregister/api/objects/2/10` | GET | Status types |
| `/index.php/apps/openregister/api/objects/2/11` | GET | Result types |
| `/index.php/apps/openregister/api/objects/2/12` | GET | Role types |

## Summary

| Status | Count |
|--------|-------|
| PASS | 20 |
| PARTIAL | 5 |
| FAIL | 3 |
| CANNOT_TEST | 2 |

## Results by Endpoint

---

### Settings API

#### GET /index.php/apps/procest/api/settings
- **Status**: PASS
- **Endpoint**: `/index.php/apps/procest/api/settings`
- **Response**: status 200, returns `{ success: true, config: { register, case_schema, task_schema, ... } }`
- **Notes**: Returns 26 schema IDs mapping to OpenRegister schemas. Works correctly with `/index.php` prefix.

#### POST /index.php/apps/procest/api/settings
- **Status**: PASS
- **Response**: status 200, `{ success: true, config: {...} }`
- **Notes**: Updates individual settings fields. Returns full updated config.

#### POST /index.php/apps/procest/api/settings/load
- **Status**: PASS
- **Response**: status 200, `{ success: true, message: "Configuration imported and auto-configured (26 schemas mapped)", version: "0.4.0", configured: 26 }`
- **Notes**: Forces re-import of register configuration from JSON definition. Useful for re-initialisation.

#### Settings URL Mismatch — CRITICAL BUG
- **Status**: FAIL
- **Bug**: The frontend JavaScript (`settings.js` and `main.js`) calls `/apps/procest/api/settings` (without `/index.php`), which returns **404 Not Found**.
- **Correct URL**: `/index.php/apps/procest/api/settings`
- **Impact**: The entire app fails to initialize on load. All object store registrations are skipped (caseType, task, status, role, result, decision, etc.). Console errors visible on every page:
  - `Error fetching Procest settings: Error: Failed to fetch settings: Not Found`
  - `Error fetching case collection: Error: Object type "case" is not registered`
  - `Error fetching caseType collection: Error: Object type "caseType" is not registered`
  - `Error fetching task collection: Error: Object type "task" is not registered`
  - `Error fetching ZGW mappings: Error: Failed to fetch`
- **Affected files**: `src/store/modules/settings.js` (fetch URL on lines 22 and 54), `src/views/settings/ZgwMappingSettings.vue` (fetch URL for ZGW mappings)

---

### ZGW Mapping API

#### GET /index.php/apps/procest/api/zgw-mappings
- **Status**: PASS
- **Response**: status 200, returns `{ success: true, mappings: { catalogus: {...}, zaak: {...}, ... } }`
- **Notes**: Returns all mapping configurations with Twig-template property mappings.

#### GET /index.php/apps/procest/api/zgw-mappings/{resourceKey}
- **Status**: PASS (tested: `zaak`, `zaaktype`)
- **Response**: status 200
- **Notes**: `zaak` mapping returned `{ mapping: [] }` (empty — not yet configured); `zaaktype` returned full property mapping with Twig templates for URL, uuid, identificatie, omschrijving, etc.

---

### Cases API (OpenRegister Direct)

#### List Cases
- **Status**: PASS
- **Endpoint**: `GET /index.php/apps/openregister/api/objects/2/16`
- **Response**: status 200, `{ results: [], total: 0, page: 1, pages: 1, limit: 20, offset: 0, facets: [] }`
- **Notes**: Pagination present and correct.

#### List Cases with `_limit` parameter
- **Status**: PASS
- **Endpoint**: `GET /index.php/apps/openregister/api/objects/2/16?_limit=5`
- **Response**: status 200, `limit: 5` correctly reflected in metadata.

#### List Cases with `_search` parameter
- **Status**: PASS
- **Endpoint**: `GET /index.php/apps/openregister/api/objects/2/16?_search=test`
- **Response**: status 200, returns filtered results.

#### Create Case
- **Status**: PASS
- **Endpoint**: `POST /index.php/apps/openregister/api/objects/2/16`
- **Response**: status 201, full case object returned
- **Notes**: Required fields: `title` and `caseType`. `status` field must be a UUID reference (not a string enum). Default `priority` set to `"normal"` and `extensionCount` to `0` automatically.

#### Get Single Case
- **Status**: PASS
- **Endpoint**: `GET /index.php/apps/openregister/api/objects/2/16/{uuid}`
- **Response**: status 200, full case object

#### Update Case (PUT)
- **Status**: PASS
- **Endpoint**: `PUT /index.php/apps/openregister/api/objects/2/16/{uuid}`
- **Response**: status 200, updated object returned

#### Update Case (PATCH)
- **Status**: PASS
- **Endpoint**: `PATCH /index.php/apps/openregister/api/objects/2/16/{uuid}`
- **Response**: status 200, partial update applied correctly

#### Delete Case
- **Status**: PASS
- **Endpoint**: `DELETE /index.php/apps/openregister/api/objects/2/16/{uuid}`
- **Response**: status 204, empty body

#### Pagination
- **Status**: PASS
- **Endpoint**: `GET /index.php/apps/openregister/api/objects/2/16?_limit=1&_page=1`
- **Response**: status 200, `{ total: 1, results: 1, page: 1, pages: 1, limit: 1 }` — pagination metadata correctly computed

---

### Tasks API (OpenRegister Direct)

#### List Tasks
- **Status**: PASS
- **Endpoint**: `GET /index.php/apps/openregister/api/objects/2/17`
- **Response**: status 200, `{ results: [], total: 0, page: 1, pages: 1 }`

#### Create Task
- **Status**: PASS
- **Response**: status 201
- **Notes**: Default `status` set to `"available"` and `priority` to `"normal"` automatically. `case` field accepts UUID reference.

#### List Tasks filtered by case
- **Status**: PASS
- **Endpoint**: `GET /index.php/apps/openregister/api/objects/2/17?case={caseId}`
- **Response**: status 200, `total: 1` — filter works correctly

#### Delete Task
- **Status**: PASS
- **Response**: status 204

---

### Case Types API (OpenRegister Direct)

#### List Case Types
- **Status**: PASS
- **Endpoint**: `GET /index.php/apps/openregister/api/objects/2/9`
- **Response**: status 200, empty results

#### Create Case Type
- **Status**: PASS
- **Response**: status 201, UUID returned

#### Delete Case Type
- **Status**: PASS
- **Response**: status 204

---

### Status Types / Result Types / Role Types

#### List Status Types (schema 10)
- **Status**: PASS
- **Response**: status 200, empty

#### List Result Types (schema 11)
- **Status**: PASS
- **Response**: status 200, empty

#### List Role Types (schema 12)
- **Status**: PASS
- **Response**: status 200, empty

---

### ZGW ZRC (Zaken) API

#### GET zaken list
- **Status**: PASS
- **Endpoint**: `GET /index.php/apps/procest/api/zgw/zaken/v1/zaken`
- **Response**: status 200, `{ count: 0, next: null, previous: null, results: [] }`
- **Notes**: Public endpoint (`@PublicPage`). Uses ZGW-standard pagination format (`count`, `next`, `previous`) rather than OpenRegister pagination format.

#### GET rollen list
- **Status**: PASS
- **Response**: status 200, ZGW pagination format

#### GET statussen list
- **Status**: PASS
- **Response**: status 200, ZGW pagination format

#### GET zaakeigenschappen (nested sub-resource)
- **Status**: PASS
- **Endpoint**: `GET /index.php/apps/procest/api/zgw/zaken/v1/zaken/{uuid}/zaakeigenschappen`
- **Response**: status 200, empty results (even for non-existent zaak UUID — no 404 raised)

#### GET audit trail
- **Status**: PASS
- **Endpoint**: `GET /index.php/apps/procest/api/zgw/zaken/v1/zaken/{uuid}/audittrail`
- **Response**: status 200, returns synthetic audit entry even for non-existent UUID

#### POST zaken (create zaak) — JWT Required
- **Status**: CANNOT_TEST
- **Response**: status 401 `{ "type": "NotAuthenticated", ... }`
- **Reason**: Requires JWT Bearer token from a registered Consumer in OpenRegister. No Consumer configured in this environment.

#### POST zaken/_zoek (search)
- **Status**: FAIL
- **Endpoint**: `POST /index.php/apps/procest/api/zgw/zaken/v1/zaken/_zoek`
- **Response**: status **201** (Created) — should be **200** (OK)
- **Notes**: A search/query POST returning 201 is incorrect per ZGW standard and REST conventions. This will confuse API consumers that check the status code.

---

### ZGW ZTC / BRC / DRC / AC / NRC APIs

#### GET zaaktypen (ZTC)
- **Status**: PARTIAL
- **Response**: status 503 `{ "detail": "Authorization service not available" }`
- **Notes**: Unlike ZRC GET endpoints (which are `@PublicPage`), ZTC GET requires JWT auth middleware. The 503 indicates OpenRegister's `AuthorizationService` failed to load, not just an auth failure. Inconsistent with BRC/DRC/NRC/AC which correctly return 401.

#### GET besluiten (BRC), documenten (DRC), applicaties (AC), kanalen (NRC)
- **Status**: PARTIAL
- **Response**: status 401, `{ "type": "NotAuthenticated", "code": "not_authenticated", ... }`
- **Notes**: Correct behaviour — unauthenticated access is rejected. CANNOT_TEST with JWT as no Consumer configured.

#### ZGW JWT-authenticated endpoints (all write operations)
- **Status**: CANNOT_TEST
- **Reason**: ZGW write endpoints (POST/PUT/PATCH/DELETE) for all ZGW API groups require a valid JWT Bearer token signed by a registered Consumer. No Consumer is configured in this test environment.

---

## Error Handling Quality

| Scenario | Expected | Actual | Status |
|----------|----------|--------|--------|
| GET non-existent case UUID | 404 + message | `404 {"error":"Not Found"}` | PASS (terse) |
| POST case missing `title` + `caseType` | 400/422 + field errors | `400` + descriptive string message | PASS |
| POST case with `status: "open"` (non-UUID) | 400/422 + field error | `400` + `"Property 'status' should match format 'uuid'..."` | PASS |
| ZTC GET without JWT | 401/403 | 503 (auth service unavailable) | FAIL — should be 401 |
| BRC/DRC/AC/NRC GET without JWT | 401/403 | 401 `NotAuthenticated` | PASS |
| Settings URL without /index.php | redirect/200 | 404 HTML | FAIL — frontend uses wrong URL |
| POST _zoek search result | 200 | 201 | FAIL — wrong status code |

---

## API Response Structure

```json
// OpenRegister objects list response (cases, tasks, case types, etc.)
{
  "results": [...],
  "total": 1,
  "page": 1,
  "pages": 1,
  "limit": 20,
  "offset": 0,
  "facets": [],
  "@self": {
    "source": "database",
    "metrics": { "search": 1.76, "db_search": 1.24, "db_count": 0.42, "total": 2.17 },
    "query": { "@self": { "register": 2, "schema": 16 }, "_register": 2, "_schema": 16 },
    "rbac": true,
    "multi": true,
    "published": false,
    "deleted": false
  }
}

// ZGW ZRC list response (zaken, statussen, rollen)
{
  "count": 0,
  "next": null,
  "previous": null,
  "results": []
}

// Settings response
{
  "success": true,
  "config": {
    "register": "2",
    "case_schema": "16",
    "task_schema": "17",
    "case_type_schema": "9",
    "status_type_schema": "10",
    "result_type_schema": "11",
    "role_type_schema": "12",
    "status_record_schema": "21",
    "role_schema": "18",
    "result_schema": "19",
    "decision_schema": "20",
    "catalogus_schema": "26",
    ...26 total fields
  }
}

// ZGW error response (auth failure)
{
  "type": "NotAuthenticated",
  "code": "not_authenticated",
  "title": "Authenticatiegegevens zijn niet opgegeven.",
  "status": 401,
  "detail": "Authenticatiegegevens zijn niet opgegeven."
}

// OpenRegister validation error (400) — plain string, not structured object
"The required properties (title, caseType) are missing. Please provide values for these properties."
```

---

## Key Findings

### Critical Bugs

1. **Settings URL mismatch — app-breaking** — `src/store/modules/settings.js` calls `/apps/procest/api/settings` (without `/index.php` prefix), which returns 404. The correct URL is `/index.php/apps/procest/api/settings`. This prevents the entire app from initialising — all object types (case, task, caseType, etc.) are never registered, so every collection fetch fails with "Object type X is not registered". The same bug affects the admin settings page JS which also calls `/apps/procest/api/settings` and `/apps/procest/api/zgw-mappings`.

2. **`_zoek` returns 201 instead of 200** — `POST /api/zgw/zaken/v1/zaken/_zoek` returns HTTP 201 (Created) for a search result. The ZGW standard and REST conventions require 200 (OK) for a search/query response.

3. **ZTC GET returns 503 not 401** — ZTC endpoints return 503 "Authorization service not available" rather than 401. This is inconsistent with BRC/DRC/AC/NRC which return 401. Likely the `ZgwAuthMiddleware` fails to load `AuthorizationService` for ZTC but not for the other components, or ZTC uses a different middleware path.

### Notable Observations

4. **Validation errors are plain strings, not objects** — OpenRegister returns validation errors as raw JSON strings (e.g., `"The required properties (title, caseType) are missing..."`), not as structured objects with field-level detail. This makes machine-readable error parsing difficult for API clients.

5. **`status` field on cases requires UUID reference** — When creating a case, `status` must be a UUID pointing to a StatusType record. Providing a string like `"open"` gives a clear 400 error. This is schema-correct but may be unintuitive; the error message is descriptive.

6. **ZRC GET endpoints are public, write endpoints require JWT** — The ZRC `index`, `show`, `audittrail`, and `zaakeigenschappen` endpoints are `@PublicPage` and work without auth. Write endpoints require JWT. This intentional asymmetry aligns with ZGW spec but creates an inconsistency across ZGW API groups (ZTC, BRC, DRC etc. require JWT for all operations).

7. **Audit trail returns data for non-existent UUIDs** — `GET /zaken/{uuid}/audittrail` with a fake UUID returns 200 with a synthetic audit entry rather than 404. This may be intentional (returning empty audit for unknown objects) but can mislead callers.

8. **Admin settings UI fields are empty** — The admin settings page at `/index.php/settings/admin/procest` shows empty input fields for Register, Zaak schema, Taak schema, etc. because the settings fetch fails due to the URL bug. The backend has correct values.

---

## Console Errors Summary

- `Failed to load resource: 404` at `/apps/procest/api/settings` — settings URL bug (both main app and settings page)
- `Error fetching Procest settings: Error: Failed to fetch settings: Not Found`
- `Error: Object type "caseType" is not registered`
- `Error: Object type "case" is not registered`
- `Failed to load resource: 404` at `/apps/procest/api/zgw-mappings` — same URL bug in settings page JS
- `Error fetching ZGW mappings: Error: Failed to fetch`
- `Refused to apply style from ...` (2 errors) — CSS content-type mismatch (minor, pre-existing)
