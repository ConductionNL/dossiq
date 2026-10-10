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

## Archived as superseded (10 Oct 2026, lane L1)

Not built, and no longer buildable as written. Every task names
`src/views/cases/CaseDetail.vue`, which `fe2949d06` (feat(shell): adopt
CnAppRoot + delete obsolete per-page views) deleted: the case page is now the
manifest detail page rendered by the library. The delta spec marked the four
items IMPLEMENTED, but it was never folded into `openspec/specs/case-dashboard-view`,
which still lists REQ-CDV-07b and REQ-CDV-07c under "Not yet implemented".
That list stays the record of what is owed; a new change against the manifest
case page picks them up.
