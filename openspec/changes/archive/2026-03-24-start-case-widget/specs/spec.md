# Start Case Widget — Requirements Specification

## Overview

A Nextcloud Dashboard widget that displays available case types (zaaktypen) and allows users to quickly create a new case directly from the dashboard.

## Data Model

### Entities Involved

| Entity | Schema Slug | Role | Source |
|--------|-------------|------|--------|
| Case Type | `caseType` | Listed in widget as quick-start options | OpenRegister (read) |
| Case | `case` | Created when user starts a new case | OpenRegister (write) |

### Case Type (read-only for widget)
- `title` (string, required) — Display name in the widget card
- `description` (string) — Tooltip/subtitle text
- `icon` — Schema-level icon: `BriefcaseVariantOutline`
- `isDraft` (boolean) — Widget filters out drafts (only published types shown)

### Case (created by widget)
- `title` (string, required) — User-provided or auto-generated
- `caseType` (uuid, required) — References the selected case type
- `startDate` (date) — Set to today on creation

## Requirements

| ID | Requirement | Priority | Tier |
|----|------------|----------|------|
| REQ-SCW-001 | Widget implements `OCP\Dashboard\IWidget` and is registered in Application.php | MUST | MVP |
| REQ-SCW-002 | Widget loads case types from OpenRegister via the object store | MUST | MVP |
| REQ-SCW-003 | Widget displays case types as clickable cards with title and icon | MUST | MVP |
| REQ-SCW-004 | Clicking a case type creates a new case via OpenRegister API | MUST | MVP |
| REQ-SCW-005 | After case creation, user is navigated to the case detail page in Procest | MUST | MVP |
| REQ-SCW-006 | Widget shows empty state when no case types are configured | MUST | MVP |
| REQ-SCW-007 | Widget filters out draft case types (`isDraft: true`) | SHOULD | MVP |
| REQ-SCW-008 | All visible text uses `t('procest', '...')` for i18n (NL + EN) | MUST | MVP |
| REQ-SCW-009 | Widget has its own webpack entry point (`startCaseWidget.js`) | MUST | MVP |
| REQ-SCW-010 | CSS uses `var()` CSS variables only (NL Design compatible) | MUST | MVP |
| REQ-SCW-011 | Widget shows loading state while fetching case types | SHOULD | MVP |
| REQ-SCW-012 | Widget shows loading/disabled state during case creation | SHOULD | MVP |

## Acceptance Scenarios

### SCN-001: Widget displays case types
```
GIVEN a user has the "Start Case" widget on their Nextcloud Dashboard
AND there are 3 published case types in Procest
WHEN the widget loads
THEN 3 case type cards are displayed with their titles
AND draft case types are not shown
```

### SCN-002: User starts a new case
```
GIVEN the "Start Case" widget is showing case types
WHEN the user clicks on a case type card
THEN a new case is created via OpenRegister with:
  - title: the case type title (auto-generated)
  - caseType: the selected case type UUID
  - startDate: today's date
AND the user is navigated to /index.php/apps/procest/#/cases/{newCaseId}
```

### SCN-003: Empty state
```
GIVEN there are no case types configured in Procest
WHEN the widget loads
THEN an empty state is shown with the message "No case types configured"
AND a hint to configure case types in Procest settings
```

### SCN-004: Loading state
```
GIVEN the widget is mounted
WHEN case types are being fetched from OpenRegister
THEN a loading indicator is displayed
```

### SCN-005: Case creation in progress
```
GIVEN the user clicks a case type card
WHEN the case is being created
THEN the clicked card shows a loading state
AND other cards are disabled to prevent double-creation
```

## API Interactions

### Fetch Case Types (read)
- **Store**: `useObjectStore()` from `src/store/modules/object.js`
- **Method**: `objectStore.fetchCollection('caseType', { _limit: 50, isDraft: false })`
- **Response**: Array of case type objects

### Create Case (write)
- **Store**: `useObjectStore()`
- **Method**: `objectStore.saveObject('case', { title, caseType, startDate })`
- **Response**: Created case object with `id`

### Navigation (post-creation)
- **Target**: `/index.php/apps/procest/#/cases/{newCaseId}`
- **Method**: `window.location.href` (cross-app navigation from dashboard)
