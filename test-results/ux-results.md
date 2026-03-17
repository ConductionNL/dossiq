# procest — UX Test Results

**Date:** 2026-03-13
**Perspective:** UX
**Environment:** http://nextcloud.local
**Browser:** browser-3 (headless)
**Login:** admin

> Experimental agentic testing — results should be verified manually for critical findings.

## Summary

| Status | Count |
|--------|-------|
| PASS | 9 |
| PARTIAL | 7 |
| FAIL | 8 |
| CANNOT_TEST | 2 |

---

## Results by Feature

### Dashboard

#### Empty State / Blank Screen
- **Status**: FAIL
- **Tested**: Dashboard renders a completely blank white content area when app is unconfigured
- **Screenshot**: ux-dashboard.png
- **Console errors**: 8 errors — `Failed to fetch settings: Not Found (404)`, `Object type "case" is not registered`, `Object type "caseType" is not registered`, `Object type "statusType" is not registered`, `Object type "task" is not registered`
- **Notes**: The dashboard main content area is blank white — no empty state message, no loading indicator, no error feedback to the user. There is no onboarding prompt or guidance directing the user to admin settings. A user encountering this for the first time has no idea what to do. This is a critical UX failure for unconfigured deployments.

#### KPI Cards / Quick Actions
- **Status**: CANNOT_TEST
- **Tested**: Could not be tested — app is unconfigured and all data fetching fails silently with no visible feedback
- **Screenshot**: ux-dashboard.png
- **Notes**: No KPI cards or quick actions rendered at all. Could not assess labels or layout.

#### Navigation Sidebar
- **Status**: PASS
- **Tested**: Sidebar items (Dashboard, Mijn werk, Zaken, Taken, Documentatie, Instellingen) are all present and visible
- **Screenshot**: ux-dashboard.png
- **Console errors**: None for sidebar
- **Notes**: Navigation labels are clear Dutch. Icons accompany all items. "Instellingen" is visually separated at the bottom. Sidebar collapse toggle is present.

---

### Case List View (Zaken — `#/cases`)

#### Empty State
- **Status**: PARTIAL
- **Tested**: Navigated to `#/cases` — shows "No items found" with a search/empty icon
- **Screenshot**: ux-case-list.png
- **Console errors**: Settings 404, caseType/statusType not registered
- **Notes**: Empty state has a visual icon and text but: (1) the text is in English ("No items found") instead of Dutch, (2) it does not distinguish between "no data exists" and "API call failed". A more helpful message would be "Geen zaken gevonden" with guidance to create the first case, or "Zaken konden niet worden geladen" with an explanation when an API error occurs.

#### View Toggle (Cards/Table)
- **Status**: PASS
- **Tested**: Cards/Table toggle visible in toolbar, Table selected by default
- **Screenshot**: ux-case-list.png
- **Notes**: Toggle is clearly positioned. Labels "Cards" / "Table" are English but visually understandable.

#### Create Action
- **Status**: PASS
- **Tested**: "+ Add Item" button is present and prominent in the toolbar; clicking it opens the new case modal
- **Screenshot**: ux-case-list.png, ux-new-case-form.png
- **Notes**: Button is discoverable. However the label "Add Item" is generic English — contextual Dutch label "Nieuwe zaak" would be clearer.

#### Search / Filter Controls
- **Status**: FAIL
- **Tested**: No search bar or filter controls visible on the case list
- **Screenshot**: ux-case-list.png
- **Notes**: The case list has no visible search or filter controls. An "Actions" menu button exists but testing its contents was not possible. In a case management system this is a significant missing UX element.

---

### New Case Form (Nieuwe zaak modal)

#### Modal Quality
- **Status**: PASS
- **Tested**: Modal opens with title "Nieuwe zaak", has close button (✕), Cancel and Submit buttons
- **Screenshot**: ux-new-case-form.png
- **Console errors**: caseType collection error (empty dropdown)
- **Notes**: Modal has "Annuleren" (Cancel) and "Zaak aanmaken" (Create case) — descriptive and Dutch. Background dims. Modal is well-proportioned. Close button uses raw "✕" character rather than a proper icon with aria-label — minor accessibility concern.

#### Required Field Indicators
- **Status**: PASS
- **Tested**: "Zaaktype *" and "Titel *" are marked with asterisks. "Omschrijving" has placeholder "Optionele omschrijving..."
- **Screenshot**: ux-new-case-form.png
- **Notes**: Required field indicators are clear and consistent.

#### Zaaktype Selector (empty)
- **Status**: PARTIAL
- **Tested**: Combobox shows "Selecteer een zaaktype..." but lists no options because caseTypes cannot be fetched
- **Screenshot**: ux-new-case-form.png
- **Console errors**: `Object type "caseType" is not registered`
- **Notes**: The dropdown is empty with no explanation to the user. A message such as "Geen zaaktypen beschikbaar — configureer de app in beheerdersinstellingen" would help users understand why they cannot proceed.

#### Form Validation
- **Status**: PARTIAL
- **Tested**: Submitted empty form — validation messages appear: "Zaaktype is verplicht" and "Titel is verplicht"
- **Screenshot**: ux-new-case-validation.png
- **Console errors**: None
- **Notes**: Validation messages are in Dutch and appear inline next to each field — good pattern. However, the error text appears in a very low-contrast light pink/red color against a white background — potential WCAG AA compliance failure. The "Titel" field shows a red border and error icon clearly, but the "Zaaktype" error text below the dropdown is pale pink and barely readable.

---

### Case Detail View (`#/cases/:id`)

#### Page Header / Case Identity
- **Status**: PARTIAL
- **Tested**: Navigated to `#/cases/test-case-id` — page renders with header "Zaak" (no case name)
- **Screenshot**: ux-case-detail.png
- **Console errors**: role/roleType/task/result collections — all "not registered"
- **Notes**: The page header shows only the generic word "Zaak" with no case title, ID, or identifier. A non-existent case ID silently renders an empty, editable form rather than a "not found" error. The user could believe they are editing a real case when they are on a ghost/empty record.

#### Status Bar
- **Status**: FAIL
- **Tested**: A grey bar appears below the header showing only "—" (dash)
- **Screenshot**: ux-case-detail.png
- **Console errors**: statusType not registered
- **Notes**: The status progression bar renders as a plain grey band with a single "—" marker. No status labels, no timeline, no current status indicator. No user-facing explanation of why this area is empty. The status panel is a key navigational element in case management and should either show content or a clear "not configured" message.

#### Case Info Section
- **Status**: PARTIAL
- **Tested**: "Zaakinformatie" section is visible with Titel, Omschrijving, Zaaktype, Identificatie, Prioriteit, Vertrouwelijkheid, Behandelaar, Startdatum fields
- **Screenshot**: ux-case-detail.png
- **Notes**: Section heading is clear Dutch. Fields are well-labelled. Read-only fields (Zaaktype, Identificatie, Vertrouwelijkheid, Startdatum) show "—" instead of a value, which is acceptable for an unconfigured system. The "Behandelaar" field has a helpful placeholder "Behandelaar toewijzen...".

#### Delete Button Styling
- **Status**: FAIL
- **Tested**: "Verwijderen" button renders in light pink/salmon color next to "Opslaan"
- **Screenshot**: ux-case-detail.png
- **Notes**: The delete button uses a light pink background that is visually too similar to normal secondary action styling. It should use a clearly destructive red style, or be separated from the "Opslaan" button (e.g. moved to a danger zone section), to prevent accidental deletion.

#### Panels (Participants, Tasks, Activity)
- **Status**: PARTIAL
- **Tested**: "Deelnemers (0)", "Taken (0/0)", "Activiteit" panels visible with empty states
- **Screenshot**: ux-case-detail.png
- **Notes**: Empty states within panels are present: "Geen deelnemers toegewezen", "Nog geen taken", "Nog geen activiteit" — these are correctly in Dutch. Action buttons "Deelnemer toevoegen" and "Behandelaar toewijzen" both appear in the Deelnemers section — the relationship between the two is unclear (is a Behandelaar a type of Deelnemer?). No deadline or processing deadline panel is visible on this view.

#### Back Navigation
- **Status**: PASS
- **Tested**: "Terug naar lijst" button is present top-left
- **Screenshot**: ux-case-detail.png
- **Notes**: Clear navigation affordance back to the cases list. No dead ends.

---

### Task List View (Taken — `#/tasks`)

#### Empty State
- **Status**: PARTIAL
- **Tested**: Shows "No items found" with icon
- **Screenshot**: ux-task-list.png
- **Notes**: Same issues as case list: English "No items found", no contextual guidance for tasks specifically.

#### Page Title
- **Status**: FAIL
- **Tested**: No visible page heading on the task list view
- **Screenshot**: ux-task-list.png
- **Notes**: The task list has no page heading to orient the user, unlike "Mijn werk" which has a prominent "Mijn werk (0)" heading. The toolbar floats at the top with no title.

---

### My Work View (Mijn werk — `#/my-work`)

#### Empty State
- **Status**: PASS
- **Tested**: Shows "Geen items aan u toegewezen" with illustrative icon and explanatory subtitle
- **Screenshot**: ux-my-work.png
- **Console errors**: Case object type not registered (warning), handled gracefully
- **Notes**: This is the best empty state in the app — contextual icon, clear Dutch heading, explanatory subtitle "Zaken en taken die aan u zijn toegewezen verschijnen hier". This pattern should be replicated across all list views.

#### Filter Tabs
- **Status**: PASS
- **Tested**: "Alles (0)", "Zaken (0)", "Taken (0)" tabs and "Toon voltooide" checkbox all visible
- **Screenshot**: ux-my-work.png
- **Notes**: Tab labels include item counts (helpful). "Toon voltooide" checkbox is clearly labelled. Layout is clean.

#### Page Heading
- **Status**: PASS
- **Tested**: "Mijn werk (0)" heading is prominent and includes count
- **Screenshot**: ux-my-work.png
- **Notes**: Clear, well-positioned heading. The parenthetical count is helpful UX.

#### Temporal Grouping (Overdue / This Week / Upcoming)
- **Status**: CANNOT_TEST
- **Tested**: No work items exist, so no temporal groups are shown
- **Notes**: Could not assess overdue highlighting, temporal section headers, or visual distinction between urgency levels.

---

### Admin Settings (`/index.php/settings/admin/procest`)

#### Configuration Section
- **Status**: PARTIAL
- **Tested**: Page loads with Register + 8 schema fields, all empty. External documentation link (?) present.
- **Screenshot**: ux-admin-settings.png
- **Console errors**: Settings 404, ZGW mappings 404
- **Notes**: Field labels are clear Dutch names. "Opslaan" button is prominent. However all fields use the field label as the placeholder (e.g. placeholder="Register") — this is redundant and provides no guidance on what value to enter. The documentation link target (procest.app) may not exist. No error message shown when settings fail to load (fields just appear blank without explanation).

#### Zaaktypebeheer Section — Empty State
- **Status**: PASS
- **Tested**: Section renders with "No items found" empty state and "Add Item" / "Actions" toolbar
- **Screenshot**: ux-admin-settings.png
- **Notes**: Structure is clear. "Add Item" correctly opens the new case type inline form.

#### Case Type Create Form
- **Status**: PASS
- **Tested**: "Add Item" opens inline "Nieuw zaaktype" form with "Algemeen" and "Statussen" tabs
- **Screenshot**: ux-case-type-detail.png
- **Console errors**: None for form rendering
- **Notes**: Form has "Terug naar lijst" back button, clear heading "Nieuw zaaktype", and "Opslaan" button. Two-tab interface is intuitive. "Verwerkingsdeadline" field has a helpful example placeholder "bijv. P56D (56 dagen)" — good UX pattern.

#### Case Type Form Validation
- **Status**: PASS
- **Tested**: Submitting empty form shows "Los de validatiefouten op" summary + per-field inline errors
- **Screenshot**: ux-case-type-validation.png
- **Notes**: Validation summary message at top + red borders + inline error messages per field is the best validation pattern in the app. All messages are in Dutch.

#### ZGW API Mapping Section
- **Status**: PARTIAL
- **Tested**: Table with 11 ZGW resource rows, all "Not configured", with "Bewerken" and "Reset" actions
- **Screenshot**: ux-admin-settings.png
- **Console errors**: ZGW mappings API 404
- **Notes**: Table structure is readable. However column headers ("ZGW Resource", "Status", "Actions") and cell values ("Not configured") are in English while the rest of the page is Dutch — a language inconsistency. "Reset" button label is also English.

---

### Navigation and General UX

#### "Documentatie" Dead Link
- **Status**: FAIL
- **Tested**: Sidebar "Documentatie" item links to "#" — clicking takes user nowhere useful
- **Screenshot**: ux-dashboard.png
- **Notes**: The Documentatie nav item is a placeholder/dead link. Clicking it does not navigate anywhere. Either a documentation page should be implemented, or this item should be hidden/disabled with a "Binnenkort beschikbaar" indicator until it is ready.

#### Language Consistency (Dutch/English mix)
- **Status**: FAIL
- **Tested**: Multiple pages inspected
- **Notes**: Significant mixing of Dutch and English throughout the app — unacceptable for a Dutch government-oriented app:
  - "No items found" empty states (all list views) — should be Dutch
  - "Add Item", "Actions" toolbar buttons — should be Dutch
  - "Cards" / "Table" view toggle labels — should be Dutch
  - ZGW API Mapping section heading, column headers, "Not configured", "Reset" — should be Dutch
  - Admin sidebar group headings "Personal" / "Administration" — Nextcloud core (out of app scope)

#### API Error Feedback to Users
- **Status**: FAIL
- **Tested**: Settings API 404 causes dashboard to be completely blank with no user-facing error
- **Screenshot**: ux-dashboard.png
- **Console errors**: 8 errors visible only in browser console
- **Notes**: When the settings API fails (404), the app silently fails — users see a blank screen with no error message, no retry option, and no guidance. This is a critical UX failure. Errors are only visible in the browser developer console. A clear error banner or empty state with a link to admin settings should be shown.

#### Loading Indicators
- **Status**: PARTIAL
- **Tested**: In-app settings page (`#/settings`) shows "Loading..." spinners during data fetching
- **Screenshot**: ux-settings-in-app.png
- **Notes**: The in-app settings view does show "Loading..." spinners while fetching data — good. However these spinners persist indefinitely when API calls fail (no timeout, no error transition). On other pages (dashboard, case list) there are no loading indicators at all — data failures are silent.

---

## UX Issues Summary

| # | Issue | Severity | Page(s) |
|---|-------|----------|---------|
| 1 | Dashboard is completely blank when unconfigured — no empty state, error, or onboarding guidance | HIGH | Dashboard |
| 2 | All API errors are silent — no user-facing feedback when calls fail, only browser console | HIGH | All pages |
| 3 | "Documentatie" sidebar link is a dead placeholder link ("#") | HIGH | All pages (sidebar) |
| 4 | Mixed Dutch/English language — empty states, toolbar buttons, ZGW section all in English | HIGH | All pages |
| 5 | Case detail view silently renders an empty editable form for non-existent IDs — no "not found" error | HIGH | Case detail |
| 6 | Validation error text contrast is too low (light pink on white) — WCAG AA risk | MEDIUM | New case modal |
| 7 | "Verwijderen" (Delete) button styling insufficiently distinct from normal buttons — accidental deletion risk | MEDIUM | Case detail |
| 8 | Status bar in case detail shows only "—" with no explanation when status types are unavailable | MEDIUM | Case detail |
| 9 | Admin settings config fields use field label as placeholder — no value format guidance | MEDIUM | Admin settings |
| 10 | Zaaktype dropdown has no options and no explanation when unconfigured | MEDIUM | New case modal |
| 11 | "Deelnemer toevoegen" vs "Behandelaar toewijzen" dual buttons on case detail — relationship unclear | MEDIUM | Case detail |
| 12 | Loading spinners persist indefinitely when API fails — no timeout or error transition | LOW | In-app settings |
| 13 | Case list and task list views have no page heading — disorienting vs "Mijn werk" which does | LOW | Zaken, Taken |
| 14 | "Add Item" button label is generic English — should use contextual Dutch labels | LOW | Case list, Task list, Admin |
| 15 | Case type form has 8+ required fields — onboarding may feel very heavy for new admins | LOW | Admin settings |

---

## Console Errors Summary

- **Pages checked**: 7 (Dashboard, Case list, Task list, My Work, Case detail, Admin settings, In-app settings)
- **Pages with errors**: 6 (all except My Work — had 1 warning only)
- **Unique errors**:
  1. `Failed to fetch settings: Not Found` — `/apps/procest/api/settings` returns 404 on every page load (root cause of most failures)
  2. `Object type "case" is not registered in the store` — requires settings to be configured first
  3. `Object type "caseType" is not registered in the store`
  4. `Object type "statusType" is not registered in the store`
  5. `Object type "task" is not registered in the store`
  6. `Object type "role" is not registered in the store`
  7. `Object type "roleType" is not registered in the store`
  8. `Object type "result" is not registered in the store`
  9. `Error fetching ZGW mappings` — `/apps/procest/api/zgw-mappings` returns 404
  10. `Refused to apply style from profiler/css/profiler-toolbar.css` (MIME type mismatch — dev profiler app issue, not procest-specific)

**Root cause assessment**: The app is not configured — no OpenRegister register or schema IDs have been set in admin settings. The 404 on `/apps/procest/api/settings` suggests the PHP backend route may be missing or broken. All downstream object type registrations depend on this settings fetch succeeding, so all data operations fail across the entire app. This is the single highest-priority issue to resolve.
