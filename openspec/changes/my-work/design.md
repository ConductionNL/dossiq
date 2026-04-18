## Architecture

### Case Type Name Resolution

Fetch case types via `objectStore.fetchCollection('caseType')` on mount, build a lookup map `caseTypeId -> title`, display on case items as subtitle.

### Accessibility (WCAG AA)

- Add `role="list"` and `role="listitem"` to sections and items
- Add `aria-label` to filter tabs with active state announcement
- Add `role="tablist"` to filter tab container
- Items: `tabindex="0"` for keyboard focus, `aria-label` with entity type + title + urgency
- Overdue text: `aria-live="polite"` for dynamic announcements

### Keyboard Navigation

- Items respond to `Enter` and `Space` keypress for activation
- Filter tabs respond to `Enter` and `Space`

### Responsive Layout

- At `max-width: 768px`: stack item content vertically, reduce padding
- Hide reference/deadline columns, show as stacked info

## Decisions

1. **Case type fetched once** — all case types loaded at mount, cached in component data
2. **Keyboard handlers on items** — `@keydown.enter` and `@keydown.space` on each row

## Status

`pr-created`
