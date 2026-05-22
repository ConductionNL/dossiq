# Tasks: Milestone Tracking Implementation

## Deduplication Check

**Finding:** Milestone tracking introduces new data structures (`milestoneRecord` schema, milestone definitions on `caseType`) and new services (`MilestoneService`), but does NOT duplicate existing functionality. The existing `statusRecord` and `StatusTimeline.vue` remain unchanged. Milestones are a thin business-friendly layer (per ADR-001 guidance: app-specific business logic, not platform plumbing). No overlap with `ObjectService`, `StatusService`, or shared components found.

---

## Schema & Registration

- [ ] **TASK-MT-01: Add `milestoneRecord` schema and register milestone record configuration**
  - Add the `milestoneRecord` schema to `lib/Settings/procest_register.json` with properties: `case` (reference), `milestoneIdentifier` (string), `reached` (boolean), `reachedAt` (datetime), `triggerSource` (enum: manual/workflow/status_transition), `triggeredBy` (string), `reversedAt` (datetime, nullable), `reversalReason` (string, nullable).
  - Schema.org type: `schema:Event`.
  - Register the schema in `lib/Service/SettingsService.php::SLUG_TO_CONFIG_KEY` as `'milestone_record_schema'`.
  - Extend the existing `caseType` schema in `procest_register.json` with a `milestones` property (array of objects, each with: identifier, label, order, description, triggerEvent, mappedStatusType, expectedDurationWorkingDays, dependsOn).
  - Update `caseType` schema.org type to note the milestone array field.
  - Add seed data in `procest_register.json`: 3 example milestone sets for zaaktypes `omgevingsvergunning`, `melding_openbare_ruimte`, and one other (Dutch values, realistic labels).
  - Write unit tests: verify schema registration, seed data loads, and `milestoneRecord` objects can be created via `ObjectService`.

---

## Backend Services

- [ ] **TASK-MT-02: Implement `MilestoneService` with reach, reverse, status-change hook, and dependency evaluation**
  - Create `lib/Service/MilestoneService.php` (stateless, DI-injected with `ObjectService`, `AuthorizationService`, `LoggerInterface`).
  - Implement `reach(string $caseId, string $milestoneIdentifier, string $triggerSource, string $triggeredBy): void` — validates milestone, checks dependencies via `evaluateDependencies()`, creates milestoneRecord object.
  - Implement `reverse(string $caseId, string $milestoneIdentifier, string $reversalReason, string $userId): void` — enforces coordinator role, updates milestone record with reversedAt + reason.
  - Implement `onStatusChanged(string $caseId, string $newStatusTypeId): void` — finds all milestones mapped to the status type, calls `reach()` for each.
  - Implement `evaluateDependencies(string $caseId, string $milestoneIdentifier): array` — returns `['canReach' => bool, 'blockedBy' => [ids]]`.
  - Implement `computeDurations(string $caseId): array` — calculates working-day and calendar-day durations between reached milestones.
  - Implement `computeProgress(string $caseId): array` — returns reached count, total, percentage, current milestone, expected deadline, days overdue.
  - Implement `findOverdue(string $zaaktypeId, int $threshold_days): array` — queries active cases with unreached milestones past deadline.
  - Implement `findBottlenecks(string $zaaktypeId, float $percentile = 1.5): array` — detects cases lingering >percentile of average inter-milestone duration.
  - Add `@spec openspec/changes/milestone-tracking/tasks.md#task-mt-02` PHPDoc tag.
  - Write unit tests (>80% coverage): `MilestoneServiceTest` covering reach, reverse, status-change hook, dependency validation, duration calculation, overdue detection, bottleneck detection.

- [ ] **TASK-MT-03: Implement `MilestoneController` with authenticated, public, and ZGW endpoints**
  - Create `lib/Controller/MilestoneController.php` (thin controller, all logic delegated to `MilestoneService`).
  - Implement `GET /api/cases/{caseId}/milestones` (authenticated) — returns milestones array with progress metrics, durations.
  - Implement `POST /api/cases/{caseId}/milestones/{milestoneIdentifier}/reach` (authenticated, body: reason) — calls `MilestoneService::reach()` with `triggerSource='manual'`.
  - Implement `POST /api/cases/{caseId}/milestones/{milestoneIdentifier}/reverse` (authenticated, requires coordinator role, body: reason) — calls `MilestoneService::reverse()`.
  - Implement `POST /api/cases/{caseId}/milestones/trigger` (public, no auth, IP allowlist to n8n) — triggers milestones by event name.
  - Implement `GET /api/public/cases/{caseShareToken}/milestones` (public, share-token auth) — returns stripped-down milestone data (no identifiers, no trigger sources, localized current-step label).
  - Wire in `appinfo/routes.php` with specific routes before wildcard routes.
  - Add IP allowlist config for n8n webhook endpoint (settable in app settings).
  - Write public-serializer unit test: verify `identifier`, `triggerSource`, `triggeredBy`, `durations` are excluded from public response.
  - Add `@spec` PHPDoc tags linking to tasks.md.

- [ ] **TASK-MT-04: Integrate milestone progress into ZGW Zaken API status history**
  - Extend `lib/Controller/ZrcController.php::statusHistoryAction()` to include milestone data in status objects.
  - For each status record, check if a milestone is mapped to that status type; if so, include milestone label and identifier in the response.
  - Response format: each status object gains optional `milestone` field with `identifier`, `label`, `order`.
  - Write integration test: verify status history includes milestone data when configured.
  - Document the ZGW extension in `docs/milestone-zgw-extension.md`.

---

## Frontend Components

- [ ] **TASK-MT-05: Create `MilestoneProgress.vue` (case detail) and `MilestoneProgressBar.vue` (case list)**
  - Create `src/views/cases/components/MilestoneProgress.vue`:
    - Accepts props: `caseId`, `milestones` (array), `progress` (object), `currentMilestone` (object).
    - Renders horizontal step indicator with dots per milestone (green/amber/red/grey based on state).
    - Current milestone dot is larger; reached milestones show checkmark icon.
    - Tooltip on hover shows label, reached date/time, trigger source, who triggered.
    - Keyboard navigation: arrow keys move focus between dots, Enter to open detail panel.
    - ARIA attributes: `role="progressbar"`, `aria-valuenow`, `aria-valuemin`, `aria-valuemax`, each dot has `aria-label`.
    - Color + icon: non-color-dependent state indication (icons: checkmark, clock, warning, dot).
    - Emits `@milestone-clicked` event with milestone identifier.
    - Accessibility: meets WCAG 2.1 AA (per ADR-010 nl-design-system guidance).
  - Create `src/views/cases/components/MilestoneProgressBar.vue`:
    - Props: `caseId`, `progress` (object with reached, total), `currentMilestone` (label).
    - Renders compact bar: "3/6 | Inhoudelijke beoordeling" with filled progress background.
    - Click to navigate to case detail and scroll to milestone section.
    - Fits in case-list row (max height ~30px).
  - Reuse: NL Design System tokens for colors (green, amber, red, grey).
  - Write component tests: render states, keyboard navigation, accessibility.

- [ ] **TASK-MT-06: Create `MilestoneDetailPanel.vue` and wire to `MilestoneProgress.vue`**
  - Create `src/views/cases/components/MilestoneDetailPanel.vue`:
    - Props: `milestone` (object), `caseId`.
    - Renders as popover (triggered by dot click in `MilestoneProgress.vue`).
    - Shows: milestone label, description, order (e.g., "3 van 6"), reached date/time, trigger source, who triggered.
    - If reversal history exists: shows reversal date/time, reason, and who reversed.
    - "Reverse milestone" button (only visible if user has coordinator role on the case).
    - Click reverse button → modal with required reason field.
    - Emits `@reverse-clicked` event with milestone identifier and reason.
  - Update `MilestoneProgress.vue` to emit `@milestone-clicked` and mount the panel.
  - Wire the reverse action to call `MilestoneController::reverseAction()` via API.
  - Write component tests: render, coordinator visibility, reversal modal.

- [ ] **TASK-MT-07: Create `MilestoneConfigTab.vue` and integrate into `CaseTypeDetail.vue`**
  - Create `src/views/settings/components/MilestoneConfigTab.vue`:
    - Props: `caseType` (object), `statusTypes` (array of available status types for mapping).
    - Renders a list of milestones with drag-and-drop reordering (drag handle on left).
    - For each milestone: fields for identifier (slug, validated for uniqueness), label (Dutch text), description, optional mappedStatusType (dropdown), optional triggerEvent (text field), expectedDurationWorkingDays (number), dependsOn (checkboxes for prior milestones).
    - Add/Remove buttons to add or delete milestones.
    - Client-side validation: identifier must be slug format, must be unique within the set, dependencies must not form cycles.
    - On save, reorder updates order numbers and calls parent to save the updated caseType.
    - Integrates as a new tab in `CaseTypeDetail.vue` alongside existing tabs (status types, document types, etc.).
  - Use GridStack or Vue Draggable for drag-drop reordering.
  - Write component tests: reorder, add/remove, validation, cycle detection.

- [ ] **TASK-MT-08: Create `MilestoneKpiCard.vue` and `MilestoneFunnelWidget.vue` for dashboard**
  - Create `src/views/dashboard/components/MilestoneKpiCard.vue`:
    - Props: `zaaktypeId` (optional filter), `period` (e.g., "month", "quarter").
    - Renders KPI card with title "Mijlpaalvoortgang", showing: cases on track (count), cases overdue (count), on-time percentage.
    - Trend indicator: arrow up/down vs. previous period.
    - Click to drill down (navigate to filtered case list).
  - Create `src/views/dashboard/components/MilestoneFunnelWidget.vue`:
    - Props: `zaaktypeId` (filter by case type).
    - Renders horizontal funnel: bars per milestone with case count and percentage of total.
    - Hover shows exact count, average duration to next milestone, and trend.
    - Example: milestone 1 → 100 cases (100%), milestone 2 → 85 (85%), milestone 3 → 60 (60%), etc.
  - Update `Dashboard.vue` to include both widgets.
  - Write component tests: render, drill-down navigation, data loading.

---

## Integrations

- [ ] **TASK-MT-09: Hook case status-change events to `MilestoneService::onStatusChanged()`**
  - Identify status-change event dispatch points in the case service (e.g., `CaseService::updateStatus()` or a status transition listener).
  - Register a listener in `lib/Listener/StatusChangeListener.php` that calls `MilestoneService::onStatusChanged()` whenever a case status changes.
  - Use Nextcloud's event dispatcher pattern (`IEventDispatcher`).
  - Document the n8n webhook contract in `docs/milestone-webhook-contract.md`: POST to `/api/cases/{caseId}/milestones/trigger` with `event` and `executionId` in JSON body.
  - Test: manually change case status and verify milestone is automatically reached if mapped.

- [ ] **TASK-MT-10: Implement daily bottleneck-detection job and coordinator notifications**
  - Create `lib/BackgroundJob/BottleneckDetectionJob.php` (extends `QueuedJob`).
  - Job runs daily (schedule in `appinfo/app.php` or via `BackgroundJobListenerRegistry`).
  - For each zaaktype with milestones: calls `MilestoneService::findBottlenecks()` and `findOverdue()`.
  - For each bottleneck case: sends notification to the coordinator using `NotificationService`.
  - Notification message: "5 zaken wachten langer dan gemiddeld op mijlpaal 'Inhoudelijke beoordeling' (gemiddeld 14 dagen, deze 22+ dagen)".
  - Logs results to `logger` (e.g., "BottleneckDetectionJob: 12 bottlenecks detected across 3 zaaktypes").
  - Test: manually trigger job and verify notifications sent.

- [ ] **TASK-MT-11: Add case-list milestone filter and enhance `CaseList.vue`**
  - Add milestone filter to the case list's filter bar (`CnFilterBar` integration).
  - Filter UI: for each zaaktype's milestones, render checkboxes "Mijlpaal: [Label] (bereikt)" and "Mijlpaal: [Label] (niet bereikt)".
  - Multiple selections: OR logic (show cases at milestone 3 or milestone 4).
  - Wire filter to `ObjectService::findAll()` with appropriate query filters.
  - Enhance `CaseList.vue` to show `MilestoneProgressBar.vue` in each row (alongside or replacing the status column).
  - Test: filter by milestone, verify correct cases shown.

---

## Localization & Accessibility

- [ ] **TASK-MT-12: Add Dutch and English i18n for milestone UI and notifications**
  - Create `l10n/nl.json` and `l10n/en.json` with strings for:
    - Component labels: "Mijlpalen", "Voortgang", "Milestone Progress", etc.
    - Button labels: "Reverse milestone", "Cancel", "Save", etc.
    - Notification templates: "N zaken wachten langer dan gemiddeld op mijlpaal 'X'", etc.
    - ARIA labels: "Milestone 3 of 6: Inhoudelijke beoordeling, reached on March 5", etc.
    - Placeholder texts: "Describe why this milestone is being reversed...", etc.
  - Register in `appinfo/info.xml` with appropriate translation URLs.
  - Extract and upload to Transifex (per standard Nextcloud process).
  - Test: switch language and verify UI strings update correctly.

- [ ] **TASK-MT-13: Verify WCAG 2.1 AA accessibility and audit color contrast**
  - Run `MilestoneProgress.vue` through axe DevTools and WAVE accessibility auditors.
  - Verify `role="progressbar"`, `aria-valuenow`, `aria-valuemin`, `aria-valuemax` on main container.
  - Verify each milestone dot has `aria-label` with descriptive text.
  - Verify keyboard navigation: arrow keys, Enter, Escape.
  - Verify non-color-dependent state indication: use icons (checkmark, clock, warning) in addition to color.
  - Check color contrast ratios (green/amber/red/grey on white background): must meet WCAG AA (4.5:1 for text, 3:1 for UI components).
  - Document accessibility testing results in `docs/milestone-accessibility.md`.

---

## Testing & Documentation

- [ ] **TASK-MT-14: Write integration tests for milestone workflows end-to-end**
  - Create `tests/integration/MilestoneIntegrationTest.php`:
    - Test: create case, configure milestones, manually reach milestone, verify record created.
    - Test: reach milestone via status transition, verify automatic trigger.
    - Test: reach milestone via n8n webhook trigger, verify event matching.
    - Test: reverse milestone with coordinator role, verify reversal record.
    - Test: attempt to reverse as non-coordinator, verify denial.
    - Test: check dependency enforcement (can't reach dependent without prerequisite).
    - Test: compute durations and progress metrics, verify accuracy.
    - Test: bottleneck detection identifies lingering cases.
    - All tests use real `procest_register.json` seed data.

- [ ] **TASK-MT-15: Create developer documentation and examples**
  - Create `docs/milestone-guide.md` with:
    - Overview of the milestone layer (business-friendly progress).
    - How to configure milestones on a zaaktype (admin UI walkthrough).
    - How workflows trigger milestones (n8n webhook contract).
    - How to query milestone data via API (examples for authenticated and public endpoints).
    - Troubleshooting: common issues and debugging.
  - Create `docs/milestone-webhook-contract.md`:
    - POST endpoint URL pattern.
    - Request/response JSON schemas.
    - Example curl commands.
  - Create example zaaktype configs in a separate `docs/milestone-examples.json` with 3 detailed zaaktypes.
  - Link documentation in `README.md`.

---

## Summary

**Total tasks:** 15 (schema registration, backend services, frontend components, integrations, testing, documentation)

**Estimated effort:** ~20-25 story points (assuming 2-day sprints with 5 points/day)

**Dependencies:** All tasks are sequential or parallelizable after TASK-MT-01 (schema/registration). TASK-MT-02 and TASK-MT-03 can run in parallel; frontend components (TASK-MT-05 onwards) depend on services being ready.

**Acceptance criteria per task:** covered in the task description and the spec.md scenarios.
