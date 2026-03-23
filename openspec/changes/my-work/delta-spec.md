## Delta Spec: my-work

### REQ-MYWORK-001: Case Type Name Display — IMPLEMENTED
- Case items in My Work now show the case type name as subtitle text
- Case types are fetched on mount and cached for lookup

### Accessibility — IMPLEMENTED
- All items have `tabindex="0"` for keyboard focus
- Items have `aria-label` announcing entity type, title, and urgency status
- Filter tabs use `role="tablist"` / `role="tab"` pattern with `aria-selected`
- Section headers have `role="heading"` with appropriate level
- Keyboard activation via Enter/Space on items and tabs

### Responsiveness — IMPLEMENTED
- At viewport <= 768px, item rows stack vertically
- Reduced padding for mobile readability
- Priority and deadline info stack below title
