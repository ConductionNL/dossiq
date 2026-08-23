# Test Flow: Case Management Journey

**App:** Dossiq
**Pages:** `/apps/dossiq/cases`, `/apps/dossiq/cases/new`, `/apps/dossiq/cases/:id`, `/apps/dossiq/my-work`
**Priority:** High
**Tags:** crud, cases, status, workflow
**Personas:** zaakbehandelaar, teamleider
**Requires seed data:** Yes (case, caseType schemas)

## Preconditions
- Logged in as admin
- Dossiq and OpenRegister apps enabled
- Case type schemas registered (e.g. "Omgevingsvergunning")

## Journey: Create and manage a case

### 1. View case list
**Navigate to** `/apps/dossiq/cases`

**Verify:**
- [ ] Cards/Table toggle visible (Table selected by default)
- [ ] "Add Item" button visible
- [ ] "Actions" button visible
- [ ] If no seed data: "No items found" message
- [ ] If seed data loaded: table shows case rows with columns

### 2. Create a new case
**Click "Add Item"**

**Verify form at `/apps/dossiq/cases/new`:**
- [ ] Form appears with case type selection
- [ ] Required fields for case creation
- [ ] Save/Create button (disabled until required fields filled)
- [ ] Cancel button

**Fill in:**
- Case type: select "Omgevingsvergunning" (or first available)
- Title: "Bouwvergunning Kerkstraat 12"
- Description: "Aanvraag bouwvergunning voor uitbreiding woning"
- Priority: normal

**Click Create/Save**

**Verify:**
- [ ] Case created successfully
- [ ] Auto-generated identifier (format YYYY-NNN)
- [ ] Status set to initial status
- [ ] Deadline auto-calculated from case type

### 3. View case detail
**Click on the case in the list**

**Verify:**
- [ ] Case detail page loads
- [ ] Status shown with current status badge
- [ ] Case info: title, description, type, identifier
- [ ] Deadline/countdown visible
- [ ] Activity timeline section visible
- [ ] Tasks section visible

### 4. Change case status
**Change status** to next available status in the workflow

**Verify:**
- [ ] Status is updated
- [ ] Activity timeline logs the change
- [ ] Status timeline/progress indicator updates

### 5. Case appears in My Work
**Navigate to** `/apps/dossiq/my-work`

**Verify:**
- [ ] Heading "My Work" (h2)
- [ ] Filters: "All (N)", "Cases (N)", "Tasks (N)"
- [ ] Case appears in the list

**Click "Cases" filter**
- [ ] Only cases shown

### 6. Complete the case
**Navigate back to case detail**
**Set status** to final/completed status
**Select result** (if prompted)

**Verify:**
- [ ] Case status shows completed
- [ ] My Work shows it only when "Show completed" is checked
