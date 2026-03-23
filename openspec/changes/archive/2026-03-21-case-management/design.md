# Design: Case Management

## Architecture
- **Backend**: No own backend CRUD; all data via OpenRegister API
- **Frontend**: `CaseList.vue` (list), `CaseDetail.vue` (detail), `CaseCreateDialog.vue` (create)
- **Data model**: Case entity with CMMN 1.1 CasePlanModel semantics, Schema.org Project typing
- **ZGW mapping**: Case maps to Zaak; status, result, roles mapped to ZGW equivalents
- **Store**: Pinia object store via `createObjectStore` from `@conduction/nextcloud-vue`

## Components
| Component | Path | Purpose |
|-----------|------|---------|
| `CaseList.vue` | `src/views/cases/CaseList.vue` | Case list with filtering |
| `CaseDetail.vue` | `src/views/cases/CaseDetail.vue` | Case detail view |
| `CaseCreateDialog.vue` | `src/views/cases/CaseCreateDialog.vue` | Case creation dialog |
| `QuickStatusDropdown.vue` | `src/views/cases/components/QuickStatusDropdown.vue` | Quick status transitions |
| `StatusTimeline.vue` | `src/views/cases/components/StatusTimeline.vue` | Visual status timeline |
| `DeadlinePanel.vue` | `src/views/cases/components/DeadlinePanel.vue` | Deadline tracking |
| `ActivityTimeline.vue` | `src/views/cases/components/ActivityTimeline.vue` | Activity feed |
| `ParticipantsSection.vue` | `src/views/cases/components/ParticipantsSection.vue` | Participant roles |
| `ResultSection.vue` | `src/views/cases/components/ResultSection.vue` | Case result |

## Validation
- `src/utils/caseValidation.js` — case creation/update validation
- `src/utils/caseHelpers.js` — case utility functions
- Deadline auto-calculated from case type processingDeadline
