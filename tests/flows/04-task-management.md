# Test Flow: Task Management

**App:** Procest
**Pages:** `/apps/procest/tasks`, `/apps/procest/tasks/new`, `/apps/procest/my-work`
**Priority:** High
**Tags:** crud, tasks, assignment
**Personas:** zaakbehandelaar, teamleider
**Requires seed data:** Yes (task, case schemas)

## Preconditions
- Logged in as admin
- Procest and OpenRegister apps enabled
- A case exists (from flow 03)

## Journey: Create and complete a task linked to a case

### 1. View task list
**Navigate to** `/apps/procest/tasks`

**Verify:**
- [ ] Cards/Table toggle visible (Table selected)
- [ ] "Add Item" button visible
- [ ] "Actions" button visible
- [ ] If no seed data: "No items found"

### 2. Create a task
**Click "Add Item"**

**Verify form fields:**
- [ ] Title field (required)
- [ ] Description field
- [ ] Case link (select existing case)
- [ ] Due date
- [ ] Priority selection
- [ ] Assignee selection
- [ ] Status (default: available)

**Fill in:**
- Title: "Beoordeel bouwtekening"
- Description: "Controleer de ingediende bouwtekening op volledigheid"
- Case: select "Bouwvergunning Kerkstraat 12"
- Due date: next week
- Priority: high
- Assignee: admin

**Click Create/Save**

### 3. Verify task in list
**Navigate to** `/apps/procest/tasks`

**Verify:**
- [ ] Task visible in table
- [ ] Shows title, status, due date

### 4. Verify in My Work
**Navigate to** `/apps/procest/my-work`
**Click "Tasks" filter**

**Verify:**
- [ ] Task appears (assigned to admin)
- [ ] Shows correct priority and due date

### 5. Complete the task
**Open task detail**
**Change status** to "completed"
**Save**

**Verify:**
- [ ] Task status updated
- [ ] Hidden from My Work unless "Show completed" checked

### 6. Actions menu
**Navigate to** `/apps/procest/tasks`
**Click "Actions"**

**Verify:**
- [ ] Refresh
- [ ] Import
- [ ] Export
