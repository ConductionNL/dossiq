## Tasks

### TASK-1: Add responsive tablet layout CSS
- **Spec ref**: REQ-CDV-07b
- **Files**: `src/views/cases/CaseDetail.vue`
- **Acceptance**: At viewport <= 1200px, panels stack in single column

### TASK-2: Add print stylesheet
- **Spec ref**: REQ-CDV-07c
- **Files**: `src/views/cases/CaseDetail.vue`
- **Acceptance**: Ctrl+P shows clean printable view with hidden actions and text status

### TASK-3: Add skeleton loading per panel
- **Spec ref**: REQ-CDV-01d
- **Files**: `src/views/cases/CaseDetail.vue`
- **Acceptance**: During loading, each panel shows skeleton placeholder instead of single spinner

### TASK-4: Add case not found state
- **Spec ref**: REQ-CDV-01c
- **Files**: `src/views/cases/CaseDetail.vue`
- **Acceptance**: Invalid case ID shows "Zaak niet gevonden" with back button
