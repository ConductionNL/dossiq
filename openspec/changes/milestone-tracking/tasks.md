# Tasks

- [ ] TASK-MT-01: Add `milestoneRecord` schema to `procest_register.json` and register `milestone_record_schema` in `SettingsService::SLUG_TO_CONFIG_KEY`; extend the `caseType` schema with a `milestones[]` array (identifier, label, order, description, triggerEvent, mappedStatusType, expectedDurationWorkingDays, dependsOn).
- [ ] TASK-MT-02: Implement `MilestoneService` with `reach`, `reverse`, `onStatusChanged`, `evaluateDependencies`, `computeDurations`, `computeProgress`, `findOverdue`, and `findBottlenecks`; enforce coordinator-only reversal.
- [ ] TASK-MT-03: Implement `MilestoneController` with authenticated, public/citizen-token, and ZGW-compatible endpoints; integrate the ZGW representation into the existing `ZrcController` status history.
- [ ] TASK-MT-04: Create `MilestoneProgress.vue` (case detail) and `MilestoneProgressBar.vue` (case list) with WCAG 2.1 AA ARIA roles, keyboard navigation, and non-color status indicators.
- [ ] TASK-MT-05: Create `MilestoneDetailPanel.vue` showing reach metadata and reversal history; wire it to dot clicks in `MilestoneProgress.vue`.
- [ ] TASK-MT-06: Add `MilestoneConfigTab.vue` to `CaseTypeDetail.vue` with drag-and-drop reorder, identifier/label editor, optional status-type mapping and trigger-event selector, and dependency picker with cycle validation.
- [ ] TASK-MT-07: Add `MilestoneKpiCard.vue` and `MilestoneFunnelWidget.vue` to `Dashboard.vue`; add milestone filter to the case list view.
- [ ] TASK-MT-08: Hook status-change events to `MilestoneService::onStatusChanged()` and document the n8n webhook contract for `/api/cases/{id}/milestones/trigger`.
- [ ] TASK-MT-09: Add the daily bottleneck-detection job (n8n or system cron) that flags cases lingering >1.5x the average inter-milestone duration and notifies the coordinator.
- [ ] TASK-MT-10: Add Dutch + English i18n for milestone UI, KPI labels, and notification templates.
