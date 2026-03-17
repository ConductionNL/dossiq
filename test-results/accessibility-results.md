# procest — Accessibility Test Results

**Date:** 2026-03-13
**Perspective:** Accessibility
**Environment:** http://nextcloud.local
**Browser:** browser-5 (headless, 1920×1080)
**Login:** admin
**Standard:** WCAG 2.1 Level AA

> Experimental agentic testing — results should be verified manually for critical findings.
> Note: OpenRegister backend is not configured in this environment. All data API calls fail (HTTP 500 / object type not registered), so Case Detail view and populated list/table states could not be tested.

---

## Summary

| Status | Count |
|--------|-------|
| PASS | 18 |
| PARTIAL | 5 |
| FAIL | 8 |
| CANNOT_TEST | 2 |

---

## Results by Page

### Dashboard (`#/`)

#### Images / Alt Text
- **Status**: PASS
- **Findings**: 0 images without alt text

#### Buttons Without Accessible Labels
- **Status**: FAIL
- **Findings**: 1 icon-only button without `aria-label`, `title`, or text. It contains a QR-code scan SVG (`.qrcode-scan-icon`) and appears in the Nextcloud contacts menu area. The SVG inner icon is correctly `aria-hidden="true"`, but the button itself has no accessible name. Present on every page.

#### Form Labels
- **Status**: PASS
- **Findings**: 0 unlabeled form inputs on the dashboard

#### Heading Structure
- **Status**: FAIL
- **Findings**: No `<h1>`–`<h6>` elements found in the main content area. The dashboard renders via a custom `<cndashboardpage>` web component that did not produce visible content (API errors). No `<h1>` exists anywhere in the procest app shell — app pages rely on section headings (`<h2>`) only on admin pages.

#### Landmark Roles
- **Status**: PASS
- **Findings**: `<main>` (1), `<nav>` (3), `<header role="banner">` (1). Good landmark coverage from the Nextcloud shell.

#### Skip Links
- **Status**: PASS
- **Findings**: Two skip links present and functional: "Doorgaan naar app-navigatie" → `#app-navigation-vue` and "Naar hoofdinhoud gaan" → `#app-content-vue`.

#### Navigation `aria-label`
- **Status**: FAIL
- **Findings**: Three `<nav>` landmarks on the page. The app's primary sidebar nav (`#app-navigation-vue`) has no `aria-label` or `aria-labelledby`. When multiple navigations exist, each must be uniquely labelled (WCAG 2.4.1 / best practice per ARIA landmarks spec). The Nextcloud app bar nav has `aria-label="Applicatiemenu"` (correct). The settings menu nav has an `aria-labelledby`. The procest sidebar nav is unlabelled.

- **Screenshot**: accessibility-dashboard.png

---

### Case List View (`#/cases`)

#### Images / Alt Text
- **Status**: PASS
- **Findings**: 0 images without alt text

#### Buttons Without Accessible Labels
- **Status**: FAIL
- **Findings**: Same persistent QR-code scan icon button — 1 unlabeled button (see Dashboard). All other buttons on the page ("Add Item", "Actions") have visible text content.

#### Radio Buttons (Cards / Table view toggle)
- **Status**: PASS
- **Findings**: Both radio inputs use `aria-labelledby` referencing valid DOM elements: "Cards" text (`id=kihau`) and "Table" text (`id=nlsij`). Correctly accessible.

#### Table Headers / Sort Controls
- **Status**: CANNOT_TEST
- **Findings**: No table rendered due to API errors (`Error: Object type "caseType" is not registered`). Cannot evaluate `<th scope>` or `aria-sort` attributes on the table view.

#### Heading Structure
- **Status**: FAIL
- **Findings**: No headings in the main content area. The page title "Zaken" is communicated only via the active sidebar navigation link (`aria-current="page"`), not a visible heading.

#### Empty State Accessibility
- **Status**: PASS
- **Findings**: Empty state uses `role="note"` with `aria-labelledby` pointing to a valid heading ID containing "No items found". Text-based — not color-only.

- **Screenshot**: accessibility-case-list.png

---

### New Case Form (modal on `#/cases`)

#### Images / Alt Text
- **Status**: PASS
- **Findings**: 0 images without alt text

#### Form Field Labels
- **Status**: FAIL
- **Findings**: Three form fields lack proper label association:
  - **Zaaktype** select/search input (`id=select-input-mfqns`): no `<label for>`, no `aria-label`, no `aria-labelledby`. Visible label "Zaaktype *" is a `<div class="form-group">`, not a `<label>`.
  - **Titel** text input (`id=inputsqbco`): no `<label for>`, no `aria-label`, no `aria-labelledby`. Visible label "Titel *" is a `<div class="form-group">`.
  - **Omschrijving** textarea (no `id` attribute): no `<label for>`, no `aria-label`, no `aria-labelledby`, and no `id` to associate a label with.

#### Required Field Indicators
- **Status**: FAIL
- **Findings**: "Zaaktype *" and "Titel *" use a `*` character in their visual div-label to signal required status. Neither `required` nor `aria-required="true"` is set on the corresponding inputs. Screen readers will not announce these fields as required.

#### Modal / Dialog Role
- **Status**: FAIL
- **Findings**: The "Nieuwe zaak" overlay has no `role="dialog"`, no `aria-modal="true"`, no `aria-labelledby`. The `<h3>` heading "Nieuwe zaak" is not linked to any container. Focus does not move into the modal when it opens. Screen readers cannot identify this as a dialog, and keyboard focus management is absent.

#### Close Button
- **Status**: FAIL
- **Findings**: The close button displays "✕" (Unicode `U+2715`) as its only text content, with no `aria-label` or `title`. Screen readers typically announce this as "multiplication sign" or similar. Should be `aria-label="Sluiten"`.

#### Heading in Modal
- **Status**: PARTIAL
- **Findings**: The heading "Nieuwe zaak" uses `<h3>`. There is no `<h1>` or `<h2>` in the app content area, so the heading hierarchy jumps directly to `<h3>`. The heading is semantically present but the hierarchy is broken.

#### Dropdown Toggle Button (vs-select)
- **Status**: PARTIAL
- **Findings**: The vue-select dropdown toggle button has `aria-labelledby` pointing to `vs-bovsv__listbox`, but the referenced element exists with empty text content (listbox has no items due to API failure). In a working environment this should be re-verified.

- **Screenshot**: accessibility-new-case-form.png

---

### Case Detail View

- **Status**: CANNOT_TEST
- **Findings**: No cases exist in the system (API errors prevent data load; `Error: Object type "caseType" is not registered`). Cannot navigate to a case detail view. Status timeline, panel headings, role assignment UI, and task sub-section accessibility cannot be evaluated.

---

### Task List View (`#/tasks`)

#### Images / Alt Text
- **Status**: PASS
- **Findings**: 0 images without alt text

#### Buttons Without Accessible Labels
- **Status**: FAIL
- **Findings**: Same 1 unlabeled QR-code scan icon button.

#### Radio Buttons (Cards / Table view toggle)
- **Status**: PASS
- **Findings**: Both radio inputs use `aria-labelledby` pointing to valid label elements — correctly accessible.

#### Heading Structure
- **Status**: FAIL
- **Findings**: No headings in task list main content area.

#### Empty State
- **Status**: PASS
- **Findings**: `role="note"` with `aria-labelledby` — correctly accessible text-based empty state.

- **Screenshot**: accessibility-task-list.png

---

### My Work View (`#/my-work`)

#### Images / Alt Text
- **Status**: PASS
- **Findings**: 0 images without alt text

#### Heading Structure
- **Status**: PASS
- **Findings**: `<h2>Mijn werk (0)</h2>` present in main content — the only view with a proper content heading.

#### Filter Tab Buttons
- **Status**: FAIL
- **Findings**: Three buttons ("Alles (0)", "Zaken (0)", "Taken (0)") function as filter tabs but:
  - No `role="tab"` on the buttons
  - No `role="tablist"` on their container element
  - No `aria-selected` attribute — the active state is conveyed only via CSS class `my-work__tab--active`
  - WCAG 1.3.1 violation: selected/active state is not programmatically determinable by assistive technologies.

#### "Toon voltooide" Checkbox
- **Status**: PASS
- **Findings**: Checkbox is wrapped in a `<label>` element containing "Toon voltooide" — correctly associated via parent label pattern. No `id` required since the label wraps the input.

#### Buttons Without Accessible Labels
- **Status**: FAIL
- **Findings**: Same 1 persistent unlabeled QR-code scan icon button.

- **Screenshot**: accessibility-my-work.png

---

### Admin Settings (`/index.php/settings/admin/procest`)

#### Images / Alt Text
- **Status**: PASS
- **Findings**: 0 images without alt text

#### Heading Structure
- **Status**: PASS
- **Findings**: Well-structured: `<h1>` "Beheerder instellingen: Procest", then `<h2>` sections: "Personal", "Administration" (sidebar), "Configuratie", "Zaaktypebeheer", "ZGW API Mapping" (main content). Correct hierarchy.

#### Form Labels (Configuration Section)
- **Status**: PASS
- **Findings**: All 9 configuration text inputs (Register, Zaak schema, Taak schema, Status schema, Rol schema, Resultaat schema, Besluit schema, Zaaktype schema, Statustype schema) have proper `<label for="...">` associations confirmed.

#### `aria-live` on Input Elements
- **Status**: PARTIAL
- **Findings**: All 9 configuration text inputs have `aria-live="polite"` set on the `<input>` element itself. The `aria-live` attribute is intended for container elements that receive dynamic content updates — placing it directly on `<input>` elements is non-standard and may cause unexpected screen reader announcements (e.g. reading the value on every keystroke). This appears to be a `@nextcloud/vue` NcInputField component behavior and is present in the in-app settings view too.

#### Table Headers (ZGW API Mapping)
- **Status**: PARTIAL
- **Findings**: The ZGW API Mapping `<table>` has 3 `<th>` column headers ("ZGW Resource", "Status", "Actions") but none have a `scope="col"` attribute (WCAG 1.3.1). The table also has no `<caption>` or `aria-label`/`aria-labelledby`. The cell content uses readable text ("Not configured") — not color-only — which is good.

#### External Documentation Link
- **Status**: PASS
- **Findings**: The icon link inside the "Configuratie" `<h2>` heading has `aria-label="Externe documentatie voor Configuratie"` and `title="Externe documentatie voor Configuratie"` — correctly named. Link opens to `https://procest.app`.

#### Navigation Sidebar
- **Status**: PASS
- **Findings**: Nextcloud admin navigation uses `<nav>` with `<h2>` section headings ("Personal", "Administration") and `<ul><li><a>` link lists. Good semantic structure.

- **Screenshot**: accessibility-admin.png

---

## Accessibility Issues Summary

| # | Issue | Page(s) | WCAG Criterion | Severity | Details |
|---|-------|---------|----------------|----------|---------|
| 1 | Icon-only button (QR scan) without accessible name | All pages | 4.1.2 Name, Role, Value (A) | High | QR-code scan button in Nextcloud contacts menu has no `aria-label`, `title`, or text content. |
| 2 | New case form fields lack programmatic labels | New Case Form | 1.3.1 Info and Relationships (A) | High | "Zaaktype", "Titel", and "Omschrijving" have only div-based visual labels — no `<label>`, `aria-label`, or `aria-labelledby`. |
| 3 | Required fields not marked with `aria-required` | New Case Form | 1.3.1 Info and Relationships (A) | High | "Zaaktype *" and "Titel *" use a star character visually but no `required` or `aria-required="true"` on inputs. |
| 4 | Modal lacks `role="dialog"`, `aria-modal`, focus management | New Case Form | 4.1.2 (A), 2.1.2 No Keyboard Trap (A) | High | "Nieuwe zaak" overlay: no dialog role, no aria-modal, no aria-labelledby, no focus trap, focus not moved into modal on open. |
| 5 | Close button (✕) has no accessible name | New Case Form | 4.1.2 (A) | Medium | Button text is "✕" (Unicode multiplication sign) with no `aria-label`. Screen readers may announce it as "multiplication sign". |
| 6 | No headings in main content areas | Dashboard, Cases, Tasks | 2.4.6 Headings and Labels (AA), 1.3.1 (A) | Medium | Three of six views have no `<h1>`–`<h6>` in main content. Only My Work and admin pages have content headings. |
| 7 | Filter tabs missing ARIA tab pattern | My Work | 1.3.1 (A) | Medium | "Alles / Zaken / Taken" buttons lack `role="tab"`, `role="tablist"` container, and `aria-selected`. Active state is CSS-only. |
| 8 | App sidebar navigation unlabelled | All app pages | 2.4.1 Bypass Blocks (A) | Low | `#app-navigation-vue` nav has no `aria-label` or `aria-labelledby`. With 3 nav landmarks on page, each should be uniquely identified. |
| 9 | Table headers missing `scope="col"` | Admin / App Settings | 1.3.1 (A) | Low | ZGW API Mapping table `<th>` elements have no `scope` attribute. Table also lacks `<caption>` or `aria-label`. |
| 10 | Heading hierarchy: `<h3>` without `<h1>`/`<h2>` parent | New Case Form | 1.3.1 (A) | Low | "Nieuwe zaak" modal uses `<h3>` but the app content area has no `<h1>` or `<h2>`. Broken heading hierarchy. |
| 11 | "Documentatie" sidebar link is a non-functional placeholder | All app pages | 2.4.4 Link Purpose (A) | Low | Navigation item links to `href="#"` — not functional, no destination. Keyboard/screen reader users reach a dead link. |
| 12 | `aria-live="polite"` on `<input>` elements | Admin / App Settings | Best practice | Info | All config text inputs carry `aria-live="polite"`. Non-standard placement may cause unexpected screen reader behavior. |

---

## Console Errors Summary

Unique error categories observed during testing:

| Error | Pages | Impact |
|-------|-------|--------|
| `Refused to apply style from '...'` | App pages, Admin | CSS MIME type issue in dev environment; may affect styled focus indicators |
| `Failed to load resource: .../apps/procest/api/settings` (404) | All | Settings API not available; causes all configuration to be empty |
| `Error fetching Procest settings: Error: Failed to fetch` | All | OpenRegister not configured |
| `Error: Object type "caseType" is not registered` | Cases, Tasks, My Work | Prevents all case/task data from loading; causes CANNOT_TEST for Case Detail |
| `Error fetching case/caseType/statusType/task collection` | All | All data API calls fail |
| `Error fetching ZGW mappings: Error: Failed to fetch` | Settings | ZGW mapping data unavailable |
| `[WARN] @nextcloud/vue: You need to fill in the 'name' prop` | All | Component prop warning; may affect aria attributes on NcInputField |

**Testing impact:** API errors mean the app renders only empty states throughout. Case Detail View and populated list/table views could not be tested. All findings are based on structural/shell-level accessibility of forms, navigation, and empty states.
