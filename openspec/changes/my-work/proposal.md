# Proposal: my-work

## Summary

Formalize and complete the My Work personal productivity hub for Procest case handlers. The core MVP view is substantially implemented; this change fills the remaining gaps: case type name display on case items, keyboard navigation (Tab/Enter), ARIA screen reader support, Dutch localization completeness, and responsive mobile layout.

## Motivation

Case handlers start each day asking "What do I need to work on next?" The My Work view (`src/views/MyWork.vue`) answers this by aggregating all cases where the user is `assignee` (non-final status) and all tasks assigned to the user (status `available` or `active`) into a single urgency-grouped list — Overdue, Due This Week, Upcoming, No Deadline — sorted by priority then deadline within each group.

The MVP core is live: filter tabs (All/Cases/Tasks), grouped sections, overdue highlighting, item navigation, empty states, show-completed toggle, and Nextcloud dashboard widgets. However, the following accessibility and UX requirements remain unimplemented:

- **REQ-MYWORK-001**: Case type name not displayed on case items (spec requires it)
- **Accessibility**: No ARIA labels, no keyboard navigation, overdue indicator relies partly on color alone
- **Responsiveness**: No mobile/narrow viewport CSS — breaks at < 768px
- **Localization**: `t()` calls present but Dutch translations in `l10n/nl.json` incomplete

## Affected Projects

- [x] Project: `procest` — `src/views/MyWork.vue`, `src/utils/dashboardHelpers.js`

## Scope

### In Scope (this change)
- **REQ-MYWORK-001 (gap)**: Display case type name on case items as subtitle
- **Accessibility**: `role="tablist"` / `role="tab"` on filter tabs, `role="list"` / `role="listitem"` on sections, `tabindex="0"` and `aria-label` on item rows, keyboard activation (Enter/Space)
- **Responsiveness**: Media query at `max-width: 768px` — stack item fields vertically, reduce padding
- **Localization**: Audit and complete Dutch translations for all My Work strings in `l10n/nl.json`
- **Seed data**: Add 3–5 realistic Dutch `case` and `task` objects to `lib/Settings/procest_register.json`
- **Deduplication check**: Verify no overlap with existing OpenRegister services

### Out of Scope (deferred)
- **REQ-MYWORK-008**: Cross-app Pipelinq integration (V1 — separate change)
- Task data source migration from CalDAV to OpenRegister `task` schema objects (separate change — requires OpenRegister object-interactions)
- Auto-refresh / WebSocket real-time updates (separate change)

## Approach

All changes are confined to `src/views/MyWork.vue`. Case type name resolution fetches the `caseType` collection once at mount and builds a lookup map. ARIA attributes are added declaratively to the template. Keyboard handlers use `@keydown.enter` and `@keydown.space` on item rows. Responsive CSS uses a scoped `@media` block. Localization is an audit of all `t()` calls against `l10n/nl.json`.
