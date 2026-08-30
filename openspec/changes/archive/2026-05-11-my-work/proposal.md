## Why

My Work is substantially implemented but missing several quality improvements: case type name display on case items, keyboard navigation, ARIA labels for accessibility, and responsive mobile layout. These are non-functional requirements specified in the my-work spec.

## What Changes

- **REQ-MYWORK-001**: Display case type name on case items in My Work
- **Accessibility**: Add ARIA labels to items, section headers, and filter tabs
- **Keyboard navigation**: Add tabindex and keyboard event handlers for item navigation
- **Responsiveness**: Add CSS for narrow viewports

## Capabilities

### Modified Capabilities
- `my-work-view`: Enhanced with case type display, accessibility, keyboard nav, responsive layout

## Impact

- **Frontend**: `src/views/MyWork.vue` — add case type resolution, ARIA attributes, keyboard handlers, responsive CSS
