# Test Flow: Sidebar Navigation

**App:** Dossiq
**Page:** all pages
**Priority:** Critical
**Tags:** navigation, sidebar
**Personas:** all

## Preconditions
- Logged in as admin
- Dossiq app enabled

## Steps

### 1. All navigation items visible
**Navigate to** `/apps/dossiq`

**Verify sidebar contains these items:**
- [ ] Dashboard (link to `/apps/dossiq/`)
- [ ] My Work (link to `/apps/dossiq/my-work`)
- [ ] Cases (link to `/apps/dossiq/cases`)
- [ ] Tasks (link to `/apps/dossiq/tasks`)
- [ ] Documentation (link to `#`, placeholder)
- [ ] Settings button at bottom

### 2. Navigation works for each item
**Click each sidebar link and verify:**
- [ ] Cases → URL contains `/cases`, shows Cards/Table toggle + Add Item + Actions
- [ ] Tasks → URL contains `/tasks`, shows Cards/Table toggle + Add Item + Actions
- [ ] My Work → URL contains `/my-work`, shows All/Cases/Tasks filters with counts
- [ ] Dashboard → shows heading "Dashboard" with New Case + New Task buttons
