# Design: my-work

## Architecture

My Work is a purely frontend feature — no new backend endpoints. It queries two existing OpenRegister schemas in the `procest` register:

- **Cases**: `objectStore.fetchCollection('case', { '_filters[assignee]': currentUser })` — returns non-final cases where the user is handler
- **Tasks**: `objectStore.fetchCollection('task', { '_filters[assignee]': currentUser, '_filters[status][in]': 'available,active' })` — returns open tasks assigned to the user

Client-side, `getGroupedMyWorkItems()` in `src/utils/dashboardHelpers.js` normalizes both shapes into a unified `WorkItem` and groups by urgency bucket.

## Decisions

### DD-01: Case Type Name Resolution

**Decision**: Fetch all case types via `objectStore.fetchCollection('caseType')` once at component mount. Build an in-memory map `{ [caseTypeId]: title }`. Display the resolved name as subtitle on case items.

**Rationale**: Case type collection is small (< 100 items). A single prefetch avoids per-row lookups while keeping the component stateless relative to the case type store.

**Fallback**: If case type is not in the map (deleted or permission issue), display nothing (graceful degradation — no error thrown).

### DD-02: ARIA Pattern for List + Tabs

**Decision**: Apply the ARIA `tablist`/`tab`/`tabpanel` pattern to filter tabs, and `list`/`listitem` to urgency section rows.

**Rationale**: WCAG 2.1 SC 1.3.1 requires semantic structure conveyed programmatically. NcTabs is not used here (custom implementation) so ARIA roles must be set manually.

**Item `aria-label` format**: `"{entityType}: {title}, {urgencyStatus}, deadline {relativeText}"` — e.g., "Zaak: Bouwvergunning Keizersgracht, 5 days overdue, deadline 20 april 2026".

### DD-03: Keyboard Navigation Strategy

**Decision**: Add `tabindex="0"` to every item row div. Activate with `@keydown.enter` and `@keydown.space` calling the same `onItemClick` handler.

**Rationale**: Item rows are `<div>` elements (not `<button>` or `<a>`) for layout flexibility. Adding `tabindex="0"` + keyboard handlers converts them to keyboard-accessible interactive elements per WCAG 2.1 SC 2.1.1.

**Focus management**: No custom focus trap — standard browser Tab order traverses items top-to-bottom.

### DD-04: Responsive Layout

**Decision**: Scoped `@media (max-width: 768px)` block in `MyWork.vue` changes item rows from horizontal (badge + title + deadline inline) to vertical stacking.

**Rationale**: At 768px (tablet minimum per NL Design System requirement), the three-column item layout becomes unreadable. Stacking preserves all fields without scrolling.

### DD-05: Show Completed Toggle — Supplemental Fetch

**Decision**: When the "Show completed" toggle is enabled, make an additional `fetchCollection` call for cases with final status and tasks with status `completed`. Append results in a visually-muted "Completed" section at the bottom.

**Rationale**: Default queries exclude completed items for performance. Loading them only on demand keeps initial load fast.

### DD-06: Parallel Data Fetching

**Decision**: Cases and tasks are fetched in parallel via `Promise.all([fetchCases(), fetchTasks()])`. Case types are fetched in parallel with the main data fetch.

**Rationale**: The My Work page load target is 1 second for ≤ 100 items. Sequential fetches would exceed this budget. `Promise.all` halves the I/O wait time.

## File Map

### Modified Files

| File | Changes |
|------|---------|
| `src/views/MyWork.vue` | Add case type subtitle display, ARIA roles/labels, keyboard handlers (`@keydown.enter`, `@keydown.space`), responsive CSS (`@media max-width: 768px`) |
| `l10n/nl.json` | Complete Dutch translations for all My Work `t()` keys |
| `lib/Settings/procest_register.json` | Add seed data: 5 `case` objects + 5 `task` objects |

### No New Files

All changes are contained in existing files. No new Vue components or PHP controllers are introduced.

## Reuse Analysis

Per ADR-001, the following OpenRegister platform capabilities are reused and MUST NOT be rebuilt:

| Capability | Platform Service / Component | How Used |
|------------|------------------------------|----------|
| Case list fetch | `ObjectService` / `createObjectStore('case')` | `fetchCollection` with `assignee` filter |
| Task list fetch | `ObjectService` / `createObjectStore('task')` | `fetchCollection` with `assignee` + status filter |
| Case type lookup | `ObjectService` / `createObjectStore('caseType')` | `fetchCollection` for name resolution map |
| Item navigation | Vue Router (`$router.push`) | Navigate to `CaseDetail` on item click |
| Empty state | `NcEmptyContent` from `@conduction/nextcloud-vue` | "No items assigned to you" + "All caught up!" |
| Dashboard widgets | `CnWidgetRenderer` / Nextcloud Dashboard API | `MyTasksWidget.php`, `OverdueCasesWidget.php` |

No custom search endpoints, pagination logic, or CRUD controllers are introduced.

## Seed Data

The following seed objects are added to `lib/Settings/procest_register.json` under `components.objects[]` for development and QA use. All use the `@self` envelope for idempotent import.

### `case` Seed Objects (5)

```json
{
  "@self": { "register": "procest", "schema": "case", "slug": "seed-case-2026-0042" },
  "title": "Bouwvergunning Keizersgracht 123",
  "identifier": "2026-0042",
  "caseType": "omgevingsvergunning",
  "status": "in-behandeling",
  "deadline": "2026-04-25",
  "priority": "high",
  "assignee": "jan"
}
```

```json
{
  "@self": { "register": "procest", "schema": "case", "slug": "seed-case-2026-0038" },
  "title": "Subsidieaanvraag innovatieproject MKB Centrum",
  "identifier": "2026-0038",
  "caseType": "subsidieaanvraag",
  "status": "besluitvorming",
  "deadline": "2026-04-28",
  "priority": "normal",
  "assignee": "jan"
}
```

```json
{
  "@self": { "register": "procest", "schema": "case", "slug": "seed-case-2026-0048" },
  "title": "Subsidieaanvraag verduurzaming woningcomplex Jordaan",
  "identifier": "2026-0048",
  "caseType": "subsidieaanvraag",
  "status": "in-behandeling",
  "deadline": "2026-05-03",
  "priority": "normal",
  "assignee": "jan"
}
```

```json
{
  "@self": { "register": "procest", "schema": "case", "slug": "seed-case-2026-0050" },
  "title": "Bezwaar aanslag OZB 2025 Prinsengracht 77",
  "identifier": "2026-0050",
  "caseType": "bezwaar",
  "status": "ontvangen",
  "deadline": "2026-05-15",
  "priority": "low",
  "assignee": "marieke"
}
```

```json
{
  "@self": { "register": "procest", "schema": "case", "slug": "seed-case-2026-0055" },
  "title": "Klacht overlast bouwwerkzaamheden Herengracht",
  "identifier": "2026-0055",
  "caseType": "klacht",
  "status": "in-behandeling",
  "deadline": null,
  "priority": "normal",
  "assignee": "jan"
}
```

### `task` Seed Objects (5)

```json
{
  "@self": { "register": "procest", "schema": "task", "slug": "seed-task-001" },
  "title": "Documenten controleren op volledigheid",
  "case": "seed-case-2026-0042",
  "assignee": "jan",
  "dueDate": "2026-04-22",
  "priority": "high",
  "status": "active"
}
```

```json
{
  "@self": { "register": "procest", "schema": "task", "slug": "seed-task-002" },
  "title": "Aanvullende informatie opvragen bij aanvrager",
  "case": "seed-case-2026-0048",
  "assignee": "jan",
  "dueDate": "2026-04-26",
  "priority": "normal",
  "status": "available"
}
```

```json
{
  "@self": { "register": "procest", "schema": "task", "slug": "seed-task-003" },
  "title": "Contact opnemen met bezwaarmaker",
  "case": "seed-case-2026-0050",
  "assignee": "jan",
  "dueDate": "2026-04-30",
  "priority": "normal",
  "status": "available"
}
```

```json
{
  "@self": { "register": "procest", "schema": "task", "slug": "seed-task-004" },
  "title": "Besluit voorbereiden en opstellen",
  "case": "seed-case-2026-0042",
  "assignee": "jan",
  "dueDate": "2026-05-05",
  "priority": "normal",
  "status": "available"
}
```

```json
{
  "@self": { "register": "procest", "schema": "task", "slug": "seed-task-005" },
  "title": "Bezwaarschrift beoordelen op ontvankelijkheid",
  "case": "seed-case-2026-0050",
  "assignee": "marieke",
  "dueDate": "2026-05-10",
  "priority": "normal",
  "status": "available"
}
```

## Risks / Trade-offs

- **[Risk] Case type resolution on large registers** — If the `caseType` collection contains many items (> 200), the prefetch adds latency. Mitigation: the collection fetch is in parallel with case/task fetches (DD-06), so it does not add to the critical path.
- **[Risk] Task data source (CalDAV vs OpenRegister)** — The current implementation uses `fetchTasksForCases()` via CalDAV. This change does NOT migrate to OpenRegister `task` schema objects — that requires the OpenRegister object-interactions change. This is a known technical debt item tracked separately.
- **[Trade-off] No auto-refresh** — My Work relies on manual page reload for concurrent state changes (REQ-MYWORK-010). WebSocket/polling is deferred. The spec uses manual refresh as the acceptable degradation path.
