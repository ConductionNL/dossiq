# Test Flow: Dashboard Overview

**App:** Dossiq
**Page:** `/apps/dossiq`
**Priority:** Critical
**Tags:** smoke, dashboard
**Personas:** zaakbehandelaar, teamleider

## Preconditions
- Logged in as admin
- Dossiq and OpenRegister apps enabled

## Steps

### 1. Dashboard loads with correct structure
**Navigate to** `/apps/dossiq` (no trailing slash)

**Verify:**
- [ ] Heading "Dashboard" (h2) is visible
- [ ] No "Internal Server Error" or "Page not found"
- [ ] Sidebar navigation visible with 4 items + Documentation + Settings

### 2. Quick-create buttons are present
- [ ] "New Case" button visible and clickable
- [ ] "New Task" button visible and clickable
- [ ] "Refresh dashboard" button visible

### 3. Quick-create navigates to forms
**Click "New Case"** — verify navigates to case creation
**Go back, click "New Task"** — verify navigates to task creation
