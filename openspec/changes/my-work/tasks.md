## Tasks

- [x] TASK-1: Add case type name to case items
  - **Spec ref**: REQ-MYWORK-001
  - **Files**: `src/views/MyWork.vue`
  - **Acceptance**: Case items show case type name (e.g., "Omgevingsvergunning") below title
  - **Verified 2026-06-13**: `getCaseTypeName()` + `caseTypeMap` resolve caseType ids via `objectStore.fetchCollection('caseType', …)`; rendered as `.my-work__case-type` span under each case item.

- [x] TASK-2: Add ARIA attributes for accessibility
  - **Spec ref**: Non-functional (Accessibility)
  - **Files**: `src/views/MyWork.vue`
  - **Acceptance**: Screen readers announce entity type, title, urgency on item focus
  - **Verified 2026-06-13**: `role="tablist"`/`aria-selected` on the filter tabs; each item carries an `:aria-label` announcing Case/Task + title + daysText.

- [x] TASK-3: Add keyboard navigation
  - **Spec ref**: Non-functional (Accessibility)
  - **Files**: `src/views/MyWork.vue`
  - **Acceptance**: Tab through items, Enter/Space to activate
  - **Verified 2026-06-13**: items carry `tabindex="0"` with `@keydown.enter`/`@keydown.space.prevent` activating `onItemClick`; tabs use the same keydown handlers.

- [x] TASK-4: Add responsive CSS
  - **Spec ref**: Non-functional (Responsiveness)
  - **Files**: `src/views/MyWork.vue`
  - **Acceptance**: Readable layout at 768px viewport width
  - **Verified 2026-06-13**: `@media (max-width: 768px)` block present in the component styles.
