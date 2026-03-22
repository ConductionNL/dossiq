# Design: Case Dashboard View

## Architecture
- **Frontend**: `CaseDetail.vue` composing multiple panels into a single working screen
- **Components**: Timeline, documents, status, tasks, participants, decisions, linked objects
- **Data**: All data from OpenRegister via object store, cross-referencing case sub-entities
- **Pattern**: Panel-based composition with interactions between panels

## Components
| Component | Path | Purpose |
|-----------|------|---------|
| `CaseDetail.vue` | `src/views/cases/CaseDetail.vue` | Main case detail view |
| `StatusTimeline.vue` | `src/views/cases/components/StatusTimeline.vue` | Visual status progression |
| `ActivityTimeline.vue` | `src/views/cases/components/ActivityTimeline.vue` | Activity feed |
| `DeadlinePanel.vue` | `src/views/cases/components/DeadlinePanel.vue` | Deadline tracking |
| `ParticipantsSection.vue` | `src/views/cases/components/ParticipantsSection.vue` | Participant management |
| `ResultSection.vue` | `src/views/cases/components/ResultSection.vue` | Case result display |
| `QuickStatusDropdown.vue` | `src/views/cases/components/QuickStatusDropdown.vue` | Quick status change |
