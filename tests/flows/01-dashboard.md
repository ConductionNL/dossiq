# Test Flow: Dashboard Overview

**App:** Procest
**Page:** `/apps/procest`
**Priority:** Critical
**Tags:** smoke, dashboard
**Personas:** zaakbehandelaar, teamleider

## Preconditions
- Logged in as admin
- Procest and OpenRegister apps enabled

## Steps

### 1. Dashboard loads with correct structure
**Navigate to** `/apps/procest` (no trailing slash)

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
