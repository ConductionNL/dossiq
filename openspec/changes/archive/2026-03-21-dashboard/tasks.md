# Tasks: Dashboard

## Task 1: KPI cards row [MVP] [DONE]
- **spec_ref**: dashboard/spec.md#REQ-DASH-001
- **files**: `src/views/dashboard/KpiCards.vue`
- **acceptance**: Four KPI cards showing open cases, overdue, completed this month, my tasks

## Task 2: Cases by status chart [MVP] [DONE]
- **spec_ref**: dashboard/spec.md#REQ-DASH-002
- **files**: `src/views/dashboard/StatusChart.vue`
- **acceptance**: Status distribution chart rendered

## Task 3: Overdue cases panel [MVP] [DONE]
- **spec_ref**: dashboard/spec.md#REQ-DASH-003
- **files**: `src/views/dashboard/OverduePanel.vue`
- **acceptance**: Overdue cases listed with navigation

## Task 4: My Work preview [MVP] [DONE]
- **spec_ref**: dashboard/spec.md#REQ-DASH-004
- **files**: `src/views/dashboard/MyWorkPreview.vue`
- **acceptance**: Personal workload items shown

## Task 5: Activity feed [MVP] [DONE]
- **spec_ref**: dashboard/spec.md#REQ-DASH-005
- **files**: `src/views/dashboard/ActivityFeed.vue`
- **acceptance**: Recent activity events displayed

## Task 6: Quick actions and refresh [MVP] [DONE]
- **spec_ref**: dashboard/spec.md
- **files**: `src/views/Dashboard.vue`
- **acceptance**: New Case, New Task buttons; refresh button

## Task 7: Unit tests (ADR-009) [DONE]
- **spec_ref**: ADR-009
- **acceptance**: Dashboard component tests pass

## Task 8: Documentation and screenshots (ADR-010) [DONE]
- **spec_ref**: ADR-010
- **files**: `docs/features/dashboard.md`, `docs/screenshots/dashboard.png`
- **acceptance**: Dashboard documented with screenshots

## Task 9: i18n support (ADR-005) [DONE]
- **spec_ref**: ADR-005
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance**: Dashboard strings in English and Dutch
