# Start Case Widget — Technical Design

## File List

| File | Action | Purpose |
|------|--------|---------|
| `lib/Dashboard/StartCaseWidget.php` | Create | PHP widget class implementing `IWidget` |
| `src/views/widgets/StartCaseWidget.vue` | Create | Vue component rendering case type cards |
| `src/startCaseWidget.js` | Create | Webpack entry point, registers with `OCA.Dashboard` |
| `lib/AppInfo/Application.php` | Modify | Register `StartCaseWidget::class` |
| `webpack.config.js` | Modify | Add `startCaseWidget` entry |

## Component Architecture

```
StartCaseWidget.php (IWidget)
  ├── getId()      → 'procest_start_case_widget'
  ├── getTitle()   → t('Start case')
  ├── getOrder()   → 15
  ├── getIconClass() → 'icon-procest-widget'
  ├── getUrl()     → link to Procest dashboard
  └── load()       → Util::addScript + Util::addStyle

startCaseWidget.js (entry point)
  └── OCA.Dashboard.register('procest_start_case_widget', callback)
      └── Mounts StartCaseWidget.vue

StartCaseWidget.vue
  ├── data: loading, creating, caseTypes[]
  ├── computed: objectStore (useObjectStore)
  ├── mounted: fetchCaseTypes()
  ├── methods:
  │   ├── fetchCaseTypes() → objectStore.fetchCollection('caseType')
  │   └── startCase(caseType) → objectStore.saveObject('case', ...) → navigate
  └── template:
      ├── loading → NcLoadingIcon
      ├── empty → NcEmptyContent
      └── cards → grid of case type buttons
```

## Data Flow

1. **Widget mount**: `StartCaseWidget.vue` calls `fetchCaseTypes()` on mount
2. **Fetch**: Object store queries OpenRegister for `caseType` objects (non-draft)
3. **Render**: Case types displayed as clickable cards in a CSS grid
4. **Click**: User clicks a card → `startCase(caseType)` is called
5. **Create**: Object store saves a new `case` object with the selected case type
6. **Navigate**: `window.location.href` redirects to the new case in Procest

## Seed Data

No new seed data required. The widget reads existing case types already seeded by the Procest register configuration (`procest_register.json`). Case types are created through the Procest admin interface.

## CSS Strategy

Widget uses scoped styles with CSS variables for NL Design compatibility:
- `var(--color-primary)` for card hover/active states
- `var(--color-background-hover)` for card background on hover
- `var(--color-main-text)` for text
- `var(--border-radius-large)` for card corners
