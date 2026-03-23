## Tasks

### TASK-1: Add defaultAssignee and intakeChannel to schemas
- **Spec ref**: REQ-INTAKE-03a, REQ-INTAKE-11a
- **Files**: `lib/Settings/procest_register.json`
- **Acceptance**: caseType schema has `defaultAssignee` field; case schema has `intakeChannel` enum field

### TASK-2: Add intake channel dropdown to CaseCreateDialog
- **Spec ref**: REQ-INTAKE-11a, REQ-INTAKE-11b
- **Files**: `src/views/cases/CaseCreateDialog.vue`
- **Acceptance**: Dropdown with 6 channel options; defaults to `manual`; value stored on created case

### TASK-3: Auto-assign handler on case creation
- **Spec ref**: REQ-INTAKE-03a, REQ-INTAKE-03c
- **Files**: `src/views/cases/CaseCreateDialog.vue`
- **Acceptance**: If selectedCaseType.defaultAssignee exists, set assignee on new case object

### TASK-4: Display intake channel on case detail
- **Spec ref**: REQ-INTAKE-08d, REQ-INTAKE-11b
- **Files**: `src/views/cases/CaseDetail.vue`
- **Acceptance**: Case info card shows "Bron: [translated channel]"

### TASK-5: Set intakeChannel on ZGW API intake
- **Spec ref**: REQ-INTAKE-08b
- **Files**: `lib/Service/ZgwZrcRulesService.php`
- **Acceptance**: Cases created via ZGW API get `intakeChannel = 'zgw-api'`

### TASK-6: Apply defaultAssignee on ZGW API intake
- **Spec ref**: REQ-INTAKE-03a
- **Files**: `lib/Service/ZgwZrcRulesService.php`
- **Acceptance**: If zaaktype has defaultAssignee, apply to case on API creation
