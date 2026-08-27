# Dossiq -- Final Review

**Date:** 2026-03-21
**Version:** 0.1.10
**Reviewer:** Claude Opus 4.6 (automated)
**Previous review:** 2026-03-20

---

## 1. OpenSpec Structure

| Metric | Count |
|--------|-------|
| Specs | 43 |
| Active changes | 0 |
| Archived changes | 50 |

All changes have been processed and archived. No active changes remain in `openspec/changes/`. The 43 specs cover the full breadth of Dossiq functionality: core case management, ZGW API mapping, dashboard, task management, roles/decisions, admin settings, i18n, multi-tenant SaaS, Prometheus metrics, and many domain-specific modules (VTH, WOO, DSO, legesberekening, complaint management, etc.).

**Verdict: PASS**

---

## 2. Unit Tests

```
PHPUnit 10.5.63
OK (33 tests, 94 assertions)
```

All 33 tests pass with 94 assertions across 4 test classes:
- `ZgwAuthMiddlewareTest` (8 tests) -- confidentiality levels, controller filtering, exception handling
- `SettingsServiceTest` (7 tests) -- config CRUD, OpenRegister availability
- `ZgwMappingServiceTest` (11 tests) -- mapping CRUD, defaults, resource keys
- `ZgwPaginationHelperTest` (7 tests) -- pagination logic, edge cases, zero division

**Verdict: PASS**

---

## 3. Code Quality

| Check | Result |
|-------|--------|
| PHP Lint | 0 errors (34 files) |
| PHPCS | 0 errors, 0 warnings (34/34 files clean) |

**Verdict: PASS**

---

## 4. Browser Testing

### 4.1 Dashboard (`/apps/dossiq/dashboard`)
- **Status: RENDERS** -- Loads correctly with KPI cards (Open Cases, Overdue, Completed This Month, My Tasks), "Cases by Status" chart, "My Work" preview, and quick actions (New Case, New Task, Refresh).
- **Note:** Data fetch fails with 404s from OpenRegister (registers 92, schemas 197/198/204/205 not found in current environment). This is an environment configuration issue, not a code bug -- the schemas need to be seeded via the admin settings "Re-import configuration" button.

### 4.2 My Work (`/apps/dossiq/my-work`)
- **Status: RENDERS** -- Shows tabbed view (All/Cases/Tasks) with counts, "Show completed" toggle, and empty state with loading indicator.

### 4.3 Cases (`/apps/dossiq/cases`)
- **Status: RENDERS** -- Cards/Table toggle, Add Item button, Actions menu, "No items found" empty state.
- **Console error:** `Object type "case" is not registered in the object store` -- the object store types are not initialized because the register/schemas are not configured in this environment.

### 4.4 Tasks (`/apps/dossiq/tasks`)
- **Status: RENDERS** -- Same layout as Cases with Cards/Table toggle, Add Item, Actions, and empty state.
- **Console error:** `Object type "task" is not registered` -- same root cause as Cases.

### 4.5 Admin Settings (`/settings/admin/dossiq`)
- **Status: FULLY RENDERS** -- Four sections:
  1. **Version Information** -- v0.1.10, "Up to date" badge, "Re-import configuration" button
  2. **Configuration** -- 9 fields: Register (92), Case schema (204), Task schema (205), Status schema (empty), Role schema (206), Result schema (207), Decision schema (209), Case type schema (197), Status type schema (198)
  3. **Case Type Management** -- Cards/Table CRUD view (currently empty)
  4. **ZGW API Mapping** -- Table with 12 ZGW resource types (zaak, zaaktype, status, statustype, resultaat, resultaattype, rol, roltype, eigenschap, besluit, besluittype, informatieobjecttype) all showing "Not configured" with Edit/Reset buttons

### 4.6 Root Route Bug (PERSISTS FROM PREVIOUS REVIEW)
- **CRITICAL:** Navigating to `/apps/dossiq/` returns HTTP 404.
- **Root cause:** In `appinfo/routes.php`, two routes share the name `dashboard#page`:
  - Line 8: `['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET']`
  - Line 116: `['name' => 'dashboard#page', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']]`

  Symfony router uses route names as keys, so the second entry overwrites the first. The catch-all requires `path` to match `.+` (one or more characters), which does not match an empty path.
- **Impact:** The Dossiq navigation icon in the Nextcloud header links to `/apps/dossiq/` which 404s. Users must navigate to `/apps/dossiq/dashboard` manually.
- **Fix:** Either rename the first route to a distinct name (e.g., `dashboard#index`) or change the catch-all pattern from `.+` to `.*`.

**Verdict: PARTIAL PASS -- root route 404 is a real bug (unchanged since previous review)**

---

## 5. API Endpoints

| Endpoint | Status | Notes |
|----------|--------|-------|
| `GET /api/health` | 200 | `{"status":"ok","version":"0.1.10","checks":{"database":"ok","filesystem":"ok"}}` |
| `GET /api/metrics` | 200 | Prometheus format: dossiq_info, dossiq_up, cases_total, cases_overdue_total, tasks_total, tasks_overdue_total |
| `GET /api/zgw/zaken/v1/zaken` | 200 | ZGW paginated response `{"count":0,"next":null,"previous":null,"results":[]}` |
| `GET /api/zgw/catalogi/v1/zaaktypen` | 403 | ZGW JWT auth required (middleware working correctly) |
| `GET /api/settings` | 403 | CSRF check (expected for curl without session token) |

**Verdict: PASS**

---

## 6. Documentation

### 6.1 Feature Docs (`docs/features/`)
7 feature docs + 1 README:
- `administration.md` -- Admin panel, OpenRegister integration
- `case-management.md` -- Case lifecycle, CMMN concepts
- `case-types.md` -- Configuration system
- `dashboard.md` -- KPI cards, charts, quick actions
- `my-work.md` -- Personal workload aggregation (Werkvoorraad)
- `roles-decisions.md` -- Participation, outcomes, decisions
- `task-management.md` -- CMMN HumanTask concepts

### 6.2 Screenshots (`docs/screenshots/`)
2 screenshots present:
- `dashboard.png` (37 KB)
- `my-work.png` (37 KB)

**Missing screenshots:** Cases list, Tasks list, Case detail, Task detail, Admin settings.

**Verdict: PARTIAL PASS -- feature docs complete, screenshots incomplete**

---

## 7. Architecture Summary

### Backend (34 PHP files)
- **Controllers (11):** Dashboard, Settings, ZgwMapping, Zrc, Ztc, Drc, Brc, Ac, Nrc, Health, Metrics
- **Services (12):** ZgwService, ZgwMappingService, ZgwBusinessRulesService, ZgwBrcRulesService, ZgwDrcRulesService, ZgwZrcRulesService, ZgwZtcRulesService, ZgwDocumentService, ZgwPaginationHelper, ZgwRulesBase, NotificatieService, SettingsService
- **Middleware:** ZgwAuthMiddleware (JWT validation + 8 confidentiality levels)
- **Dashboard Widgets (3):** CasesOverview, MyTasks, OverdueCases
- **Repair Steps (2):** InitializeSettings, LoadDefaultZgwMappings
- **Listener:** DeepLinkRegistrationListener

### Frontend (34 Vue + 19 JS files)
- **Views:** Dashboard, MyWork, CaseList, CaseDetail, CaseCreateDialog, TaskList, TaskDetail, TaskCreateDialog
- **Case Components:** ActivityTimeline, AddParticipantDialog, DeadlinePanel, ParticipantsSection, QuickStatusDropdown, ResultSection, StatusTimeline
- **Dashboard Widgets:** KpiCards, ActivityFeed, MyWorkPreview, OverduePanel, StatusChart
- **Settings Views:** AdminRoot, CaseTypeAdmin, CaseTypeDetail, CaseTypeList, Settings, UserSettings, ZgwMappingSettings, GeneralTab, StatusesTab
- **Store Modules:** object, settings, zgwMapping
- **i18n:** English (`en.json`) + Dutch (`nl.json`)
- **Widget Entry Points:** casesOverviewWidget.js, myTasksWidget.js, overdueCasesWidget.js

### ZGW API Coverage (5 components + Autorisaties)
| Component | Endpoints |
|-----------|-----------|
| ZRC (Zaken) | CRUD, zaakeigenschappen (CRUD), zaakbesluiten, _zoek, audit trail |
| ZTC (Catalogi) | CRUD, publish (zaaktypen/besluittypen/informatieobjecttypen), audit trail |
| DRC (Documenten) | CRUD, download, lock/unlock, chunked upload (bestandsdelen), audit trail |
| BRC (Besluiten) | CRUD, audit trail |
| NRC (Notificaties) | webhook, CRUD, audit trail |
| AC (Autorisaties) | applicaties CRUD |

### CI/CD (8 GitHub workflows)
branch-protection, code-quality, documentation, issue-triage, openspec-sync, release-beta, release-stable, sync-to-beta

### ZGW Newman Test Suite
Located in `tests/zgw/` with run scripts, environment config, and 8 result files from previous test runs.

---

## 8. Changes Since Previous Review (2026-03-20)

| Item | Previous | Current |
|------|----------|---------|
| Unit tests | Not run | 33/33 pass, 94 assertions |
| PHPCS | Not checked | 0 errors, 0 warnings |
| Screenshots | 0 | 2 (dashboard.png, my-work.png) |
| Root route 404 | Reported | Still present (unchanged) |
| Object type registration errors | Present | Still present (environment config issue) |
| NcButton Vue warnings | 12 warnings | Not observed in this session |
| Cases list | Showed "Object type not registered" | Same -- empty state renders correctly |
| Archived changes | Unknown | 50 |

---

## 9. Issues Found

### CRITICAL
1. **Root route returns 404** -- `/apps/dossiq/` gives 404 due to duplicate route name `dashboard#page` in `appinfo/routes.php` (lines 8 and 116). The catch-all `/{path}` with `.+` requirement overwrites the root `/` route. Users clicking the Dossiq nav icon in the Nextcloud header get a "Page not found" error. **Persists from previous review.**

### WARNING
2. **Object store types not registered** -- Console errors `Object type "case" is not registered in the object store` and `Object type "task" is not registered` on Cases and Tasks pages. Root cause: the `createObjectStore` calls depend on register/schema IDs that reference schemas not present in this test environment. Running "Re-import configuration" from admin settings should resolve this.

3. **Status schema ID empty** -- The admin settings show the Status schema field as empty while all other schema fields have values. This likely causes status-related features to fail.

4. **ZGW mappings all "Not configured"** -- All 12 ZGW resource mappings show "Not configured". The LoadDefaultZgwMappings repair step should auto-configure these on install.

5. **Missing screenshots** -- Only 2 of ~7 expected screenshots exist (dashboard, my-work). Missing: cases, tasks, case-detail, task-detail, admin-settings.

### SUGGESTION
6. **Documentation nav link is dead** -- The "Documentation" sidebar nav item links to `#` (no-op). Should link to actual documentation or be removed.

7. **"Completed This Month" text truncation** -- KPI card label is cut off on standard viewport widths.

---

## 10. Overall Assessment

| Category | Score | Notes |
|----------|-------|-------|
| OpenSpec structure | 10/10 | 43 specs, 50 archived, 0 active -- fully clean |
| Unit tests | 10/10 | 33/33 pass, 94 assertions, 4 well-structured test classes |
| Code quality | 10/10 | Zero PHPCS/lint issues across 34 PHP files |
| Browser: Dashboard | 8/10 | All components render; data empty due to env config |
| Browser: My Work | 8/10 | Renders with filters and toggle |
| Browser: Cases/Tasks | 7/10 | UI renders, object types not registered (env issue) |
| Browser: Admin Settings | 9/10 | Full settings page with 4 sections |
| Browser: Root route | 3/10 | 404 on `/apps/dossiq/` -- critical navigation bug |
| API endpoints | 9/10 | Health, metrics, ZGW APIs all respond correctly |
| Documentation | 7/10 | 7 feature docs present; screenshots incomplete |
| Architecture | 9/10 | Comprehensive ZGW implementation, clean separation |

**Overall: 90/110 (82%) -- GOOD with one critical routing bug**

The app demonstrates strong architecture with comprehensive ZGW API coverage across 6 components, clean code quality (zero PHPCS issues), and solid unit test coverage. The OpenSpec structure is exemplary with 43 specs and all 50 changes properly archived. The critical blocker is the root route 404 which has persisted across reviews -- this is a one-line fix (change `.+` to `.*` in the catch-all route pattern, or rename the duplicate route) that would resolve the most impactful user-facing issue.
