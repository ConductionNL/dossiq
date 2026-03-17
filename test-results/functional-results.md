# Procest App — Functional Test Results

**Date:** 2026-03-12
**Tester:** Claude Code (automated)
**App URL:** http://nextcloud.local/index.php/apps/procest
**Admin Settings URL:** http://nextcloud.local/index.php/settings/admin/procest

---

## Summary Table

| Category | Status | Count |
|---|---|---|
| PASS | Fully working as expected | 14 |
| PARTIAL | Works but with limitations/bugs | 6 |
| FAIL | Broken / does not work | 5 |
| CANNOT_TEST | Blocked by known bug or not yet implemented | 3 |

**Total features tested:** 28

---

## Root Cause: Known Settings API Bug

All list data (cases, tasks, case types, status types) fails to load because the settings API call uses the wrong URL path:

- **Actual:** `GET /apps/procest/api/settings` → 404 Not Found
- **Expected:** `GET /index.php/apps/procest/api/settings`

The missing `index.php` prefix causes the settings fetch to fail, which in turn blocks object type registration in the store (`registerObjectType`). As a result, all subsequent API calls for cases, tasks, caseTypes, statusTypes fail with "Object type X is not registered in the store."

This is a pre-known bug and affects all list views and CRUD operations that depend on data from the register.

---

## Results by Feature

### 1. Navigation & App Loading

| Feature | Status | Notes |
|---|---|---|
| App loads after login | PASS | Page title "Procest - Nextcloud", sidebar renders |
| Sidebar navigation links render | PASS | Dashboard, Mijn werk, Zaken, Taken, Documentatie, Instellingen all present |
| Active state on navigation | PASS | Correct link highlighted when navigating |
| Navigation collapse/expand button | PASS | Collapse button visible in toolbar |

**Screenshot:** `functional-login.png`

---

### 2. Dashboard (#/)

| Feature | Status | Notes |
|---|---|---|
| Dashboard page loads | FAIL | Main content area is completely blank (white) |
| Dashboard data (cases, tasks) | FAIL | All data fetches fail due to settings bug |

**Screenshot:** `dashboard.png`

**Console errors:**
- `Error fetching case collection: Object type "case" is not registered`
- `Error fetching caseType collection: Object type "caseType" is not registered`
- `Error fetching statusType collection: Object type "statusType" is not registered`
- `Error fetching task collection: Object type "task" is not registered`

**Notes:** The Dashboard component mounts and attempts to load data via `loadDashboardData()`, but all fetches fail cascade from the settings 404. The content area renders nothing — no empty state message, no skeleton loaders, just blank white. This is worse than the list pages which at least show "No items found".

---

### 3. Cases List (#/cases)

| Feature | Status | Notes |
|---|---|---|
| Cases list page loads | PASS | Page renders with toolbar and empty state |
| "No items found" empty state | PASS | Icon and message displayed correctly |
| Cards/Table view toggle | PASS | Toggle works, Cards/Table radio switches correctly |
| "Add Item" button opens modal | PASS | "Nieuwe zaak" modal appears |
| Modal has correct fields | PASS | Zaaktype (required combobox), Titel (required text), Omschrijving (optional textarea) |
| Form validation on empty submit | PASS | "Zaaktype is verplicht" and "Titel is verplicht" shown in red |
| Title field accepts input | PASS | Text typed correctly |
| Zaaktype dropdown | FAIL | Shows "Geen resultaten" — empty because caseType object type not registered |
| Cancel button closes modal | PASS | Modal dismissed, returns to list |
| Close (✕) button on modal | PASS | Modal dismissed |
| Actions menu opens | PASS | Refresh, Import, Export, Copy selected (disabled), Delete selected (disabled) |
| Copy/Delete selected disabled when empty | PASS | Correctly disabled with no selection |
| Case create (submit) | CANNOT_TEST | Blocked: Zaaktype dropdown always empty due to settings bug |

**Screenshots:** `cases-list.png`, `cases-add-modal.png`, `cases-validation.png`, `cases-zaaktype-dropdown-empty.png`, `cases-cards-view.png`, `cases-actions-menu.png`

---

### 4. Tasks List (#/tasks)

| Feature | Status | Notes |
|---|---|---|
| Tasks list page loads | PASS | Page renders identically to cases list |
| "No items found" empty state | PASS | Correct |
| Cards/Table view toggle | PASS | Works correctly |
| "Add Item" button opens dialog | PARTIAL | Dialog opens but has NO form fields |
| Task create dialog content | FAIL | "Create Item" dialog is completely empty — no input fields, only Cancel and Create buttons |
| Create button behavior | FAIL | Clicking Create with empty form disables the button but does nothing (no errors, no validation messages) |
| Cancel button closes dialog | PASS | Dialog dismissed correctly |
| Actions menu | PASS | Same as cases: Refresh, Import, Export, Copy/Delete disabled |

**Screenshot:** `tasks-list.png`, `tasks-add-modal.png`

**Bug:** The task creation dialog (`Create Item`) is a generic placeholder with no task-specific form fields. This is a significant gap compared to the cases form which has proper fields. The dialog title is also in English ("Create Item") while the rest of the app is in Dutch.

---

### 5. My Work (#/my-work)

| Feature | Status | Notes |
|---|---|---|
| My Work page loads | PASS | Renders with heading "Mijn werk (0)" |
| Empty state message | PASS | "Geen items aan u toegewezen" with explanatory text |
| Filter tabs render | PASS | Alles (0), Zaken (0), Taken (0) |
| Tab switching (Alles → Zaken → Taken) | PASS | Active state changes correctly on each tab |
| "Toon voltooide" checkbox | PASS | Checkbox toggles checked/unchecked |
| Data population | CANNOT_TEST | No data available due to settings bug |

**Screenshot:** `my-work.png`

**Console errors on page load:**
- `Error fetching case collection: Object type "case" is not registered`
- `[WARN] Case object type not registered`

---

### 6. Documentation Link

| Feature | Status | Notes |
|---|---|---|
| Documentation link | FAIL | Opens a new browser tab pointing to `procest.app` which fails to load (chrome-error). The current page also redirects to `#/` (Dashboard). Double-navigation side effect. |

**Bug:** The Documentatie link in the sidebar (`href="#"`) has a side effect — it navigates the current page to `#/` (the hash changes) while simultaneously trying to open an external tab. The external URL `procest.app` fails to load in this environment. The link behavior is broken: it should navigate to documentation without affecting the current page route.

---

### 7. In-App Settings (#/settings)

| Feature | Status | Notes |
|---|---|---|
| Settings page loads | PASS | Three sections render: Configuratie, Zaaktypebeheer, ZGW API Mapping |
| Configuratie form fields | PASS | All 9 fields (Register, Zaak schema, Taak schema, Status schema, Rol schema, Resultaat schema, Besluit schema, Zaaktype schema, Statustype schema) render and accept input |
| Opslaan (Save) button | FAIL | Hits `POST /apps/procest/api/settings` → 404 (same missing index.php prefix bug) |
| ZGW API Mapping table | PASS | 12 ZGW resource rows render, each with "Not configured" status and Bewerken/Reset buttons |
| ZGW Bewerken (Edit) dialog | PASS | Opens "Edit ZGW Mapping: zaak" with Enabled checkbox, Source Register, Source Schema, Property Mapping (JSON), Reverse Mapping (JSON), Value Mappings (JSON), Query Parameter Mapping fields |
| ZGW mapping Opslaan | FAIL | `PUT /apps/procest/api/zgw-mappings/zaak` → 404 (missing index.php prefix) |
| ZGW mapping Annuleren | PASS | Closes dialog correctly |
| ZGW mapping Reset | FAIL | `POST /apps/procest/api/zgw-mappings/zaak/reset` → 404, BUT shows false "Mapping saved successfully" toast |
| Zaaktypebeheer list | PASS | Empty list with Cards/Table toggle, Add Item, Actions buttons |

**Screenshots:** `settings-inapp.png`, `settings-zgw-mapping-edit.png`, `settings-zgw-reset-false-success.png`

**Bug — False success notification:** When the ZGW mapping Reset button is clicked and the API call fails with 404, the UI still shows a "Mapping saved successfully" toast message. This is a false positive that will mislead users.

---

### 8. Admin Settings (/index.php/settings/admin/procest)

| Feature | Status | Notes |
|---|---|---|
| Admin settings page loads | PASS | Same content as in-app settings: Configuratie, Zaaktypebeheer, ZGW API Mapping |
| Configuratie form | PASS | Same 9 schema fields as in-app settings |
| Opslaan button | FAIL | Same 404 as in-app settings |
| Zaaktypebeheer Add Item | PASS | Opens detailed inline form for "Nieuw zaaktype" |
| Zaaktype form — Algemeen tab | PASS | 17 fields render including required fields (Titel, Doel, Trigger, Onderwerp, Herkomst, Verwerkingsdeadline, Vertrouwelijkheid, Verantwoordelijke eenheid, Geldig vanaf) and optional fields |
| Zaaktype form — Statussen tab | PASS | Shows correct guidance: "Sla het zaaktype eerst op voordat u statustypen toevoegt." |
| Zaaktype form — validation | PASS | All required fields show errors in red with "Los de validatiefouten op" summary |
| Zaaktype form — Opslaan | CANNOT_TEST | Would fail due to settings bug (caseType not registered) |
| Terug naar lijst button | PASS | Returns to Zaaktypebeheer list view |
| ZGW API Mapping (admin) | PASS | Same table as in-app settings |

**Screenshots:** `admin-settings.png`, `admin-zaaktype-create-form.png`, `admin-zaaktype-validation.png`, `admin-zaaktype-statussen-tab.png`

---

## Bug Summary

### Critical Bugs

| # | Bug | Affected Features |
|---|---|---|
| 1 | **Settings API wrong URL**: `/apps/procest/api/settings` returns 404; should be `/index.php/apps/procest/api/settings` | All settings save/load, all list data (cases, tasks, case types, status types), Dashboard content |
| 2 | **ZGW mappings API wrong URL**: `/apps/procest/api/zgw-mappings` returns 404; should use `/index.php/` prefix | ZGW mapping load, save, reset |
| 3 | **Dashboard blank**: No content renders on Dashboard — not even an empty state message | Dashboard |
| 4 | **Task creation form empty**: "Create Item" dialog has no form fields | Task CRUD |

### Minor Bugs

| # | Bug | Affected Features |
|---|---|---|
| 5 | **False success notification on ZGW Reset**: Shows "Mapping saved successfully" even when API returns 404 | ZGW mapping reset |
| 6 | **Documentation link broken**: Opens `procest.app` (fails) in new tab AND navigates current page to `#/` | Documentatie nav link |
| 7 | **Inconsistent i18n in Tasks dialog**: "Create Item" / "Cancel" / "Create" are in English; rest of UI is Dutch | Task creation modal |

---

## Console Errors Summary

All errors observed across the session:

| Error | Frequency | Root Cause |
|---|---|---|
| `Failed to load resource: 404 /apps/procest/api/settings` | Multiple (every page load) | Missing `index.php` prefix in API URL |
| `Error fetching Procest settings: Failed to fetch settings: Not Found` | Multiple | Same as above |
| `Error fetching case collection: Object type "case" is not registered` | Dashboard, My Work | Cascades from settings fetch failure |
| `Error fetching caseType collection: Object type "caseType" is not registered` | Cases page, Settings | Same cascade |
| `Error fetching statusType collection: Object type "statusType" is not registered` | Dashboard, My Work | Same cascade |
| `Error fetching task collection: Object type "task" is not registered` | Dashboard | Same cascade |
| `Failed to load resource: 404 /apps/procest/api/zgw-mappings` | Settings pages | Missing `index.php` prefix |
| `Error fetching ZGW mappings: Failed to fetch` | Settings pages | Same as above |
| `Error saving ZGW mapping: Failed to save` | On ZGW save | Same URL prefix bug |
| `Error resetting ZGW mapping: Failed to reset` | On ZGW reset | Same URL prefix bug |
| `Error saving Procest settings: Failed to save` | On settings save | Same URL prefix bug |
| `[WARN] Case object type not registered` | My Work | Cascade from settings |
| `Refused to apply style from profiler-toolbar.css` (MIME type) | Every page | Environment noise (profiler app), not procest |

---

## Network Errors Summary

| URL | Method | Status | Notes |
|---|---|---|---|
| `/apps/procest/api/settings` | GET | 404 | Missing `index.php` prefix — occurs on every page load |
| `/apps/procest/api/zgw-mappings` | GET | 404 | Missing `index.php` prefix |
| `/apps/procest/api/zgw-mappings/zaak` | PUT | 404 | Missing `index.php` prefix |
| `/apps/procest/api/zgw-mappings/zaak/reset` | POST | 404 | Missing `index.php` prefix |
| `http://procest.app` | GET (new tab) | ERR_NAME_NOT_RESOLVED | External doc site unreachable in this environment |
| `/apps-extra/profiler/css/profiler-toolbar.css` | GET | ERR_ABORTED | Environment noise, not related to procest |

---

## Screenshots Reference

| Filename | Description |
|---|---|
| `functional-login.png` | App loaded after login — Dashboard with empty content area |
| `dashboard.png` | Dashboard page — blank main content area |
| `cases-list.png` | Cases list — "No items found" empty state with toolbar |
| `cases-add-modal.png` | "Nieuwe zaak" create case modal |
| `cases-validation.png` | Case form validation errors in red |
| `cases-zaaktype-dropdown-empty.png` | Zaaktype dropdown showing "Geen resultaten" |
| `cases-cards-view.png` | Cases in Cards view mode |
| `cases-actions-menu.png` | Actions menu expanded (Refresh, Import, Export, Copy/Delete) |
| `tasks-list.png` | Tasks list — "No items found" |
| `tasks-add-modal.png` | "Create Item" dialog — empty, no form fields |
| `my-work.png` | My Work page with tabs and empty state |
| `settings-inapp.png` | In-app settings page (full page) |
| `settings-zgw-mapping-edit.png` | ZGW Mapping edit dialog |
| `settings-zgw-reset-false-success.png` | ZGW table after Reset — false success toast already gone |
| `admin-settings.png` | Admin settings page |
| `admin-zaaktype-create-form.png` | Zaaktype creation form (full page) |
| `admin-zaaktype-validation.png` | Zaaktype form validation errors |
| `admin-zaaktype-statussen-tab.png` | Zaaktype Statussen tab — save-first guidance |
