# Procest — Test Results Summary

**Date:** 2026-03-13
**Environment:** http://nextcloud.local
**Mode:** Full (5 of 6 perspectives — Functional agent hit rate limit)
**Method:** Automated browser testing with Playwright MCP (headless)

> Previous run: 2026-03-12. See individual perspective files for full detail.

---

## Overall Results (5 perspectives, 2026-03-13)

| Status | Count | Percentage |
|--------|-------|------------|
| **PASS** | 55 | 55% |
| **PARTIAL** | 21 | 21% |
| **FAIL** | 24 | 24% |
| **CANNOT_TEST** | 9 | 9% |

> Many CANNOT_TEST results are caused by the settings URL root-cause bug (see below) blocking data from loading.

---

## Root Cause: Settings URL Bug (Blocks Entire App)

**All 5 agents independently identified the same critical bug:**

The JavaScript frontend calls `/apps/procest/api/settings` (missing `/index.php/`), which returns **HTTP 404**. This cascades into complete app failure:

1. `fetchSettings()` fails → `initializeStores()` never registers object types
2. Every data fetch fails with `"Object type X is not registered"`
3. Dashboard, Cases, Tasks views render blank or empty
4. Admin settings form loads with all fields empty

**Fix:** In `src/store/modules/settings.js` and `src/views/settings/ZgwMappingSettings.vue`, replace hardcoded paths with `OC.generateUrl('/apps/procest/api/settings')`.

---

## FAIL Issues — Requires Attention

### Critical

| # | Finding | Perspective | Details |
|---|---------|-------------|---------|
| 1 | **Settings URL bug — app-breaking** | All | `/apps/procest/api/settings` → 404; should use `/index.php/apps/procest/api/settings` |
| 2 | **ZrcController::index() missing JWT auth** | Security | `GET /api/zgw/zaken/v1/zaken` returns HTTP 200 with no authentication — ZRC list endpoints publicly accessible |
| 3 | **ZgwAuthMiddleware scope enforcement is dead code** | Security | No `ZgwController` base class exists; middleware's `enforceScopes()` and confidentiality checks never run |

### High

| # | Finding | Perspective | Details |
|---|---------|-------------|---------|
| 4 | **Dashboard blank on unconfigured app** | UX | No empty state, no error, no onboarding guidance — just a white screen |
| 5 | **All API errors are silent** | UX | No user-facing feedback when calls fail |
| 6 | **Mixed Dutch/English language** | UX | "No items found", "Add Item", "Actions", "Cards/Table", ZGW section — all in English |
| 7 | **"Documentatie" sidebar link is dead** | UX | Links to `#`, does nothing |
| 8 | **New case form fields lack proper labels** | Accessibility | "Zaaktype", "Titel", "Omschrijving" have no `<label>` / `aria-label` |
| 9 | **Required fields not marked `aria-required`** | Accessibility | `*` shown visually but no `required` attribute on inputs |
| 10 | **"Nieuwe zaak" modal lacks dialog semantics** | Accessibility | No `role="dialog"`, no `aria-modal`, no focus trap |

### Medium

| # | Finding | Perspective | Details |
|---|---------|-------------|---------|
| 11 | **`_zoek` POST returns 201 instead of 200** | API | Search endpoint should return 200 OK per ZGW standard |
| 12 | **ZTC GET returns 503 instead of 401** | API | Inconsistent with BRC/DRC/AC/NRC which return 401 correctly |
| 13 | **Delete button styling insufficiently destructive** | UX | "Verwijderen" in light pink — too similar to safe actions |
| 14 | **Filter tabs missing ARIA tab pattern** | Accessibility | "Alles/Zaken/Taken" lack `role="tab"`, `role="tablist"`, `aria-selected` |
| 15 | **No page headings in main content** | Accessibility | Dashboard, Cases, Tasks have no `<h1>`–`<h6>` in content area |
| 16 | **Hardcoded JWT secrets in LoadDefaultZgwMappings.php** | Security | `procest-admin-secret-key-for-testing` installed by default |

---

## Results by Perspective

| Perspective | PASS | PARTIAL | FAIL | CANNOT_TEST | Key Finding |
|-------------|------|---------|------|-------------|-------------|
| Performance | 1 | 3 | 2 | 2 | All NC system calls fast (108–135ms); app blocked by URL bug |
| UX | 9 | 7 | 8 | 2 | Blank dashboard; mixed Dutch/English; dead Documentatie link |
| Security | 7 | 1 | 3 | 1 | ZRC endpoints unauthenticated; middleware dead code |
| API | 20 | 5 | 3 | 2 | OpenRegister CRUD all works; wrong URL in frontend JS |
| Accessibility | 18 | 5 | 8 | 2 | Modal has no dialog role; form fields unlabeled |
| Functional | — | — | — | — | Not completed (rate limit) |

---

## Recommendations

### Fix Immediately
1. **Fix settings URL** — use `OC.generateUrl()` in `settings.js` and `ZgwMappingSettings.vue`
2. **Add `validateJwtAuth()` to `ZrcController::index()`**
3. **Fix `ZgwAuthMiddleware`** — extend ZGW controllers from a common base class

### High Priority
4. **Add error/empty state to Dashboard** when settings fail
5. **Fix Dutch translations** across list views and toolbars
6. **Fix "Nieuwe zaak" modal accessibility** — `role="dialog"`, focus trap, field labels
7. **Fix `_zoek` HTTP status** — return 200 not 201

### Medium Priority
8. Investigate ZTC returning 503 instead of 401
9. Rotate/remove hardcoded JWT secrets
10. Add ARIA tab pattern to My Work filter tabs
11. Add page `<h2>` headings to Cases and Tasks views

### For Next Test Run
- Re-run functional perspective
- Test with a configured OpenRegister backend (configure register/schema IDs first)
- Test ZGW write endpoints once a JWT Consumer is configured
**Method:** Automated browser testing with Playwright MCP (headless, 1920×1080)

> Experimental agentic testing — results should be verified manually for critical findings.

---

## Overall Results

| Status | Count | Percentage |
|--------|-------|------------|
| **PASS** | 44 | 43% |
| **PARTIAL** | 19 | 19% |
| **FAIL** | 27 | 27% |
| **CANNOT_TEST** | 12 | 12% |

---

## #1 Critical Bug Blocking Everything

> **Settings API URL bug** — Frontend calls `/apps/procest/api/settings` (missing `/index.php/` prefix), which returns 404. This cascades into ALL data loading failures: cases, tasks, case types, status types, ZGW mappings. Every list view shows empty. The app is functionally broken on every page load.
>
> **Fix:** Change `/apps/procest/api/settings` → `/index.php/apps/procest/api/settings` in `src/store/modules/settings.js:22`. Same fix for `/apps/procest/api/zgw-mappings` in `src/store/modules/zgwMapping.js`.
>
> **Confirmed by:** Performance agent (network log), API agent (fetch test), Security agent (console errors), Functional agent (all CRUD blocked).

---

## FAIL Issues (Requires Attention)

| Feature | Perspective | Summary | Severity |
|---------|-------------|---------|----------|
| Settings API URL | Functional / API / Perf / Security | `/apps/procest/api/settings` → 404, blocks entire app | **CRITICAL** |
| ZGW Mappings URL | API / Performance | `/apps/procest/api/zgw-mappings` → 404, same root cause | **CRITICAL** |
| Task creation form | Functional / UX | "Create Item" dialog has zero form fields — placeholder UI | **HIGH** |
| Dashboard blank | Functional / UX | `CnDashboardPage` component renders nothing — no empty state | **HIGH** |
| ZgwAuthMiddleware dead code | Security | Middleware scope enforcement never fires (no `ZgwController` base class exists); any valid JWT can call any ZGW endpoint regardless of configured scopes | **HIGH** |
| "Nieuwe zaak" modal — no `role="dialog"` | Accessibility | No focus trap, Escape doesn't close, focus leaks to background | **HIGH** |
| Modal form fields — no labels | Accessibility | Zaaktype/Titel/Omschrijving inputs have no programmatic label association; screen reader cannot announce field purpose | **HIGH** |
| Silent save failure | UX | Clicking "Opslaan" when settings API fails shows no feedback — no toast, no error banner, nothing | **HIGH** |
| 14 English strings in Dutch app | UX | "No items found", "+ Add Item", "Actions", "Refresh", "Import", "Export", "Cards", "Table", "Create Item", "Cancel", "Create", "Not configured", "Reset", all ZGW edit modal labels | **MEDIUM** |
| Documentation link broken | Functional / UX | Opens `procest.app` (unreachable) in new tab + navigates current page to `#/` | **MEDIUM** |
| `_zoek` returns 201 instead of 200 | API | `POST /api/zgw/zaken/v1/zaken/_zoek` returns 201 Created — should be 200 OK | **MEDIUM** |
| ZGW not-found returns 200 | API | `GET /api/zgw-mappings/{nonexistent}` returns HTTP 200 with `success:false` — should be 404 | **MEDIUM** |
| False success on ZGW Reset | Functional | Reset button shows "Mapping saved successfully" even when API returns 404 | **MEDIUM** |
| QR scan button unlabelled | Accessibility | Icon-only button on all pages has no `aria-label` | **MEDIUM** |
| No page headings | Accessibility | Dashboard, Cases, Tasks pages have no `<h1>`/`<h2>` in main content | **MEDIUM** |

---

## PARTIAL Issues (Needs Investigation)

| Feature | Perspective | What Works | What Doesn't |
|---------|-------------|------------|--------------|
| Case creation | Functional | Form renders, Dutch validation, cancel/close | Zaaktype dropdown always empty (settings bug) |
| Zaaktype admin form | Functional | Form tabs, multiple field types, validation render | Cannot save (settings bug cascades) |
| In-app settings form | Functional / UX | Dutch labels, Opslaan button | Fields empty (settings 404), no error feedback on fail |
| ZGW Mapping edit dialog | Functional | Opens, shows fields, cancel/close | All-English labels, cannot save |
| Cases list view | UX / Functional | Empty state shows, view toggle works | Empty state says "No items found" (misleading — implies search result, not load failure) |
| `aria-current` on nav | Accessibility | Active page link gets `aria-current="page"` | Dashboard link ALSO gets `aria-current` on sub-pages (Vue Router exact-match bug) |
| Bundle sizes | Performance | No SLOW API calls, page loads <1100ms | `procest-main.js` 3.9MB / `procest-settings.js` 3.5MB decoded — no shared vendor chunk |
| ZRC single-resource auth | API | ZRC list endpoints work with NC session | Single GET/POST/PATCH on zaak UUID returns 401 — inconsistent within same API |
| Pagination | API / Performance | Pagination params (`_limit`, `_offset`, `page`) accepted | Cannot verify with real data; pagination UI not visible in DOM |
| Admin Settings headings | Accessibility | Proper H1/H2 hierarchy in admin page | Sidebar nav (`#app-navigation-vue`) has no `aria-label` |

---

## CANNOT_TEST (Blocked)

| Feature | Perspective | Reason |
|---------|-------------|--------|
| Case CRUD (full flow) | Functional | Settings bug prevents Zaaktype from loading — cannot create a case |
| Task CRUD | Functional | Create dialog has no form fields — task creation not implemented |
| Zaaktype save | Functional | Settings bug prevents save from reaching API |
| ZGW API write operations | API | ZRC POST/PATCH, ZTC, DRC, BRC, AC, NRC endpoints all require ZGW JWT (not NC session) |
| Pagination with real data | Performance | No data loads due to settings bug |
| XSS persistence (server-side) | Security | Cannot save data due to settings bug — stored XSS not verifiable |
| Case detail / timeline / activity | Functional | No cases exist to open |
| Role & decision features | Functional | Not yet implemented (roadmap V1) |

---

## Results by Perspective

### Functional (browser-2)
- **PASS**: 14 | **PARTIAL**: 6 | **FAIL**: 5 | **CANNOT_TEST**: 3
- **Key findings:**
  - Navigation, sidebar, active states, collapse button all work
  - "Nieuwe zaak" case form renders with correct Dutch validation
  - Zaaktype admin form is rich (tabs, multiple field types, validation) but cannot save
  - Tasks "Create Item" dialog is empty placeholder — no form fields at all
  - ZGW Reset shows false success notification when API returns 404

### UX (browser-3)
- **PASS**: 4 | **PARTIAL**: 6 | **FAIL**: 14 | **CANNOT_TEST**: 0
- **Key findings:**
  - "Mijn werk" page is the best-implemented: fully Dutch, meaningful empty state with icon + explanatory subtitle — use as model for all other pages
  - 14+ strings need Dutch translation (toolbar buttons, empty states, task dialog, ZGW section)
  - "Nieuwe zaak" modal: Escape doesn't close, raw ✕ character used as close button
  - Tasks modal uses NcDialog (correct); Cases modal uses custom implementation (incorrect) — inconsistent
  - Admin settings "Opslaan" fails silently — no user feedback whatsoever

### Performance (browser-4)
- **PASS**: 4 | **PARTIAL**: 2 | **FAIL**: 3 | **CANNOT_TEST**: 1
- **Key findings:**
  - Page load times good: 868ms (app), 1034ms (admin settings) — within acceptable range
  - All successful API calls <200ms — no slow endpoints
  - Settings API fires **twice** on admin settings page (two components fetch independently on mount)
  - `procest-main.js` (3.9MB) and `procest-settings.js` (3.5MB) have no shared vendor chunk — Vue/NC libraries duplicated across both bundles

### API (browser-1)
- **PASS**: 15 | **PARTIAL**: 2 | **FAIL**: 2 | **CANNOT_TEST**: 8
- **Key findings:**
  - Settings API works correctly at `/index.php/apps/procest/api/settings` — 28 config keys returned
  - All ZGW mapping CRUD operations work correctly
  - ZRC list endpoints (zaken, statussen, rollen, resultaten, zaakobjecten) all return 200 with standard paginated envelope
  - 3 HTTP status code bugs: `_zoek` returns 201, not-found mapping returns 200, invalid UUID returns 401 instead of 400/404
  - Audit trail returns raw array instead of paginated envelope (inconsistent)

### Accessibility (browser-5)
- **PASS**: 1 | **PARTIAL**: 3 | **FAIL**: 1 | **CANNOT_TEST**: 0
- **Key findings:**
  - My Work page is accessible — has heading, descriptive empty state, checkbox with implicit label
  - Skip links ("Doorgaan naar app-navigatie", "Naar hoofdinhoud gaan") present and functional
  - "Nieuwe zaak" modal fails WCAG 2.1 A on 3 criteria: no `role="dialog"`, no focus trap, no input labels
  - 5 WCAG A failures total (1.3.1, 2.1.1, 2.1.2, 2.4.6, 4.1.2) — see `accessibility-results.md` for full criterion table
  - Admin Settings page has correct heading hierarchy and form labels — best-structured page for accessibility

### Security (browser-7)
- **PASS**: 7 | **PARTIAL**: 0 | **FAIL**: 2 | **CANNOT_TEST**: 0
- **Key findings:**
  - CSRF protection correctly implemented: `requesttoken` + `OCS-APIREQUEST` on all API calls
  - XSS: all input fields properly escape content — Vue's default template binding prevents DOM injection
  - No sensitive data in API responses, console, or network traffic
  - ZGW APIs correctly reject unauthenticated requests (401 with ZGW-standard error body)
  - `ZgwAuthMiddleware.php` is dead code — `instanceof ZgwController` guard always skips because `ZgwController` doesn't exist as a base class. Scope-based authorization is never enforced.

---

## Console Errors (Across All Perspectives)

| Error | Occurrences | Root Cause |
|-------|-------------|------------|
| `404 /apps/procest/api/settings` | Every page load | Frontend URL bug — missing `/index.php/` |
| `Error fetching Procest settings` | Every page load | Cascade from above |
| `Object type "case" is not registered` | Every page load | Cascade from settings 404 |
| `Object type "caseType" is not registered` | Every page load | Cascade from settings 404 |
| `Object type "statusType" is not registered` | Every page load | Cascade from settings 404 |
| `Object type "task" is not registered` | Every page load | Cascade from settings 404 |
| `404 /apps/procest/api/zgw-mappings` | Admin settings page | Frontend URL bug — same root cause |
| `Refused to apply style: profiler-toolbar.css` | Every page load | Missing profiler debug asset (dev env artifact) |

---

## Recommendations

### High Priority (Fix before any user testing)

1. **Fix settings API URL** — Change `fetch('/apps/procest/api/settings', ...)` to `fetch('/index.php/apps/procest/api/settings', ...)` in `src/store/modules/settings.js:22`. Same fix for zgw-mappings in `src/store/modules/zgwMapping.js`. This single fix unblocks the entire app.

2. **Implement task creation form** — The Tasks "Create Item" dialog has no form fields. Needs: task title, assigned user, related case, due date, priority. Also translate to Dutch.

3. **Fix `ZgwAuthMiddleware`** — Create a `ZgwController` base class that all ZGW controllers extend, or move scope enforcement into `ZgwService::validateJwtAuth()`. Otherwise `enforceScopes()` is permanently dead code and JWT scope authorization is bypassed.

4. **Fix "Nieuwe zaak" modal accessibility** — Add `role="dialog"`, `aria-modal="true"`, `aria-labelledby`; implement focus trap; close on Escape; add `aria-label="Sluiten"` to close button; add `<label for>` or `aria-labelledby` to all form inputs.

### Medium Priority

5. **Add error feedback on save failure** — When "Opslaan" fails (API error), show a toast/NcToast or inline error. Currently fails silently.

6. **Translate remaining English strings** — At minimum: empty state texts ("Geen items gevonden"), toolbar buttons ("+ Zaak/Taak toevoegen", "Acties"), view toggles ("Kaarten", "Tabel"), task dialog title/buttons, ZGW API Mapping section.

7. **Fix HTTP status codes** — `POST /_zoek` → 200; `GET /zgw-mappings/{nonexistent}` → 404.

8. **Fix Dashboard** — `CnDashboardPage` renders blank. Show a meaningful empty state or content.

9. **Fix Documentation nav link** — Point to a real URL or remove until documentation exists.

10. **Fix false success on ZGW Reset** — Don't show "Mapping saved successfully" when the API call failed.

### Low Priority

11. **Extract shared vendor chunk** — Both JS bundles (3.9MB + 3.5MB decoded) include Vue and NC libraries. A shared chunk would roughly halve total JS download size.

12. **Deduplicate settings fetch** — Admin settings page calls settings API twice on mount. Use shared Pinia store to prevent the extra request.

13. **Fix `aria-current` duplication** — Dashboard nav link incorrectly gets `aria-current="page"` when sub-routes are active. Use exact route matching in Vue Router `<RouterLink>`.

14. **Add `aria-label` to sidebar nav** — `<nav id="app-navigation-vue">` needs `aria-label="Procest navigatie"`.

15. **Add page headings** — Dashboard, Cases, and Tasks pages need an `<h1>`/`<h2>` in the main content area.

---

## Detail Reports

| Perspective | File |
|-------------|------|
| Functional | `functional-results.md` |
| UX | `ux-results.md` |
| Performance | `performance-results.md` |
| API | `api-results.md` |
| Accessibility | `accessibility-results.md` |
| Security | `security-results.md` |
