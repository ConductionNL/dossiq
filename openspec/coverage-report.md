# Coverage Report — procest

Generated: 2026-04-20 08:44 UTC  
Branch: `fix/header-info-email-phpcs`  
Scanner: opsx-coverage-scan v1 (full per-method pass)

Supersedes the file-level `coverage-report.pilot.{md,json}` from the 2026-04-20 pilot run.

## Scope

- PHP: 89 files under `lib/` scanned (764 methods, excluding Migration/ and Db/ entity boilerplate)
- Frontend: 183 files under `src/` scanned (318 units — Vue component methods + TS/JS functions)
- 331 REQs across 46 capabilities (354 heading hits; 23 dup/synth collapsed)
- `.opsx-ignore` not present — 0 entries suppressed

## Summary

| Bucket | Count | Next action |
|---|---|---|
| annotated | 0 | — (already tagged — none: procest is fully legacy) |
| plumbing  | 102 (PHP: 102, FE: 0) | — (never tagged) |
| 1 — REQ matched | 678 (PHP: 392, FE: 286) | `/opsx-annotate procest` |
| 2a — existing capability, no REQ | 96 (7 clusters) | `/opsx-reverse-spec procest --extend <cap>` |
| 2b — no capability owner | 206 (9 clusters) | `/opsx-reverse-spec procest --cluster <name>` |
| 3a — REQ broken (code removed) | 0 | (heuristic disabled — see notes) |
| 3b — REQ never implemented | 73 | Mark deferred or remove |
| 4 — ADR conformance | 115 findings across 3 rules | Follow-up issue |

> **Large Bucket 1 (678 methods)** — consider annotating one capability at a time when `/opsx-annotate` gains `--capability` support. For now, annotate-all will produce a single large ghost change.

## Bucket 1 — Ready to annotate

Will be annotated via ghost change `retrofit-annotate-procest-2026-04-20`. Grouped by capability → file.

### capability: admin-settings — 54 methods across 33 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Sections/SettingsSection.php` | `__construct()` | REQ-ADMIN-001 | 0.95 | explicit: IIconSection implementation |
| `lib/Sections/SettingsSection.php` | `getID()` | REQ-ADMIN-001 | 0.95 | explicit: IIconSection implementation |
| `lib/Sections/SettingsSection.php` | `getName()` | REQ-ADMIN-001 | 0.95 | explicit: IIconSection implementation |
| `lib/Sections/SettingsSection.php` | `getPriority()` | REQ-ADMIN-001 | 0.95 | explicit: IIconSection implementation |
| `lib/Sections/SettingsSection.php` | `getIcon()` | REQ-ADMIN-001 | 0.95 | explicit: IIconSection implementation |
| `lib/Settings/AdminSettings.php` | `__construct()` | REQ-ADMIN-001 | 0.95 | explicit: ISettings implementation for panel registration |
| `lib/Settings/AdminSettings.php` | `getForm()` | REQ-ADMIN-001 | 0.95 | explicit: ISettings implementation for panel registration |
| `lib/Settings/AdminSettings.php` | `getSection()` | REQ-ADMIN-001 | 0.95 | explicit: ISettings implementation for panel registration |
| `lib/Settings/AdminSettings.php` | `getPriority()` | REQ-ADMIN-001 | 0.95 | explicit: ISettings implementation for panel registration |
| `src/views/settings/AdminRoot.vue` | `reimport()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/CaseTypeAdmin.vue` | `openDetail()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/CaseTypeDetail.vue` | `loadCaseType()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/CaseTypeDetail.vue` | `if()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/CaseTypeList.vue` | `fetchCaseTypes()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/CaseTypeList.vue` | `for()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/MapLayerSettings.vue` | `emptyForm()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/ParafeerRouteAdmin.vue` | `loadData()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/PartnerAdmin.vue` | `editPartner()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/Settings.vue` | `save()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/Settings.vue` | `if()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/Settings.vue` | `setTimeout()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/WorkflowEditor.vue` | `loadData()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/ZgwMappingSettings.vue` | `editMapping()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/components/DurationPicker.vue` | `onDaysChange()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/components/DurationPicker.vue` | `if()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/components/LhsMatrixAdmin.vue` | `updateCell()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/components/LhsMatrixAdmin.vue` | `if()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/components/StepConfigPanel.vue` | `parseChecklist()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/components/StepConfigPanel.vue` | `if()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/components/StepConfigPanel.vue` | `if()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/components/TransitionConfigPanel.vue` | `parseGuards()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/components/TransitionConfigPanel.vue` | `if()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/components/TransitionConfigPanel.vue` | `if()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/components/VthTemplateLibrary.vue` | `selectTemplate()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/components/WorkflowNode.vue` | `onMouseDown()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/components/WorkflowPalette.vue` | `onDragStart()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/tabs/AiSettingsTab.vue` | `updateSetting()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/tabs/AppointmentSettingsTab.vue` | `saveBackend()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/tabs/BerichtenboxSettingsTab.vue` | `testConnection()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/tabs/ChecklistAdmin.vue` | `createChecklist()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/tabs/DocumentTypesTab.vue` | `loadItems()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/tabs/PropertiesTab.vue` | `fetchPropertyDefs()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/tabs/ResultTypesTab.vue` | `loadItems()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/tabs/ResultsTab.vue` | `formatPeriod()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/tabs/ResultsTab.vue` | `if()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/tabs/ResultsTab.vue` | `if()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/tabs/RoleTypesTab.vue` | `loadItems()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/tabs/RolesTab.vue` | `genericRoleLabel()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/tabs/StatusesTab.vue` | `getEmptyForm()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/tabs/TemplatesTab.vue` | `loadTemplates()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/tabs/TemplatesTab.vue` | `generateUrl()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/tabs/TenantSettingsTab.vue` | `create()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/tabs/WorkflowTab.vue` | `loadVersions()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/settings/tabs/WorkflowTab.vue` | `if()` | REQ-ADMIN-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: bezwaar-lifecycle — 4 methods across 3 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `src/views/complaints/ComplaintDetail.vue` | `loadComplaint()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/complaints/ComplaintList.vue` | `loadComplaints()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/complaints/components/ComplaintCreateDialog.vue` | `validate()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/complaints/components/ComplaintCreateDialog.vue` | `if()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: case-dashboard-view — 12 methods across 4 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `src/views/cases/widgets/CaseDocumentsWidget.vue` | `getFileIcon()` | REQ-CDV-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/widgets/CaseDocumentsWidget.vue` | `if()` | REQ-CDV-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/widgets/CaseDocumentsWidget.vue` | `if()` | REQ-CDV-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/widgets/CaseDocumentsWidget.vue` | `if()` | REQ-CDV-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/widgets/CaseDocumentsWidget.vue` | `if()` | REQ-CDV-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/widgets/CasePropertiesWidget.vue` | `save()` | REQ-CDV-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/widgets/CasePropertiesWidget.vue` | `if()` | REQ-CDV-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/widgets/CaseTasksWidget.vue` | `dueDateClass()` | REQ-CDV-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/widgets/CaseTasksWidget.vue` | `if()` | REQ-CDV-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/widgets/CaseTasksWidget.vue` | `if()` | REQ-CDV-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/widgets/CaseTimelineWidget.vue` | `onStatusSelected()` | REQ-CDV-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/widgets/CaseTimelineWidget.vue` | `if()` | REQ-CDV-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: case-management — 93 methods across 51 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Service/CaseEmailService.php` | `extractCaseNumber()` | REQ-CM-01 | 0.75 ⚠️ NEEDS-REVIEW | name+path keyword match |
| `lib/Service/CaseEmailService.php` | `getTemplatesForCaseType()` | REQ-CM-01 | 0.75 ⚠️ NEEDS-REVIEW | name+path keyword match |
| `lib/Service/CaseEmailService.php` | `loadTemplate()` | REQ-CM-01 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/CaseEmailService.php` | `loadCaseData()` | REQ-CM-01 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/CaseEmailService.php` | `recordSentEmail()` | REQ-CM-01 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/CaseEmailService.php` | `recordReceivedEmail()` | REQ-CM-01 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/CaseEmailService.php` | `findCaseByIdentifier()` | REQ-CM-01 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `src/utils/caseHelpers.js` | `calculateDeadline()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/caseHelpers.js` | `generateIdentifier()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/caseHelpers.js` | `isCaseOverdue()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/caseHelpers.js` | `isCaseDueToday()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/caseHelpers.js` | `isCaseDueTomorrow()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/caseHelpers.js` | `getCaseOverdueText()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/caseHelpers.js` | `formatDeadlineCountdown()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/caseHelpers.js` | `getDaysElapsed()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/caseHelpers.js` | `getDaysRemaining()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/caseHelpers.js` | `formatDate()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/caseHelpers.js` | `formatDateShort()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/caseValidation.js` | `isCaseTypeUsable()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/caseValidation.js` | `getCaseTypeUnusableReason()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/caseValidation.js` | `validateCaseCreate()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/caseValidation.js` | `validateCaseUpdate()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/caseValidation.js` | `validateStatusChange()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/CaseCreateDialog.vue` | `loadCaseTypes()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/CaseDetail.vue` | `getTaskPriorityLabel()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/CaseList.vue` | `loadCaseTypes()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/CaseList.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/CaseList.vue` | `for()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/ActivityTimeline.vue` | `getIcon()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/AddParticipantDialog.vue` | `submit()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/AddParticipantDialog.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/AdvicePanel.vue` | `defaultDeadline()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/AdviceRequestPanel.vue` | `getStatusLabel()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/AiAssistantPanel.vue` | `askQuestion()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/AiAssistantPanel.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/AiClassifyDialog.vue` | `classify()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/AiExtractDialog.vue` | `extract()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/AiSuggestionCard.vue` | `formatValue()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/AiSuggestionCard.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/AiSummaryPanel.vue` | `generate()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/AppointmentBookingDialog.vue` | `loadSlots()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/AppointmentBookingDialog.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/AppointmentSection.vue` | `loadAppointments()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/BerichtenboxComposeDialog.vue` | `validate()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/BerichtenboxComposeDialog.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/BerichtenboxTab.vue` | `loadMessages()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/CaseTransferDialog.vue` | `submitTransfer()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/ConsultationPanel.vue` | `getStatusLabel()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/CreateShareDialog.vue` | `createShare()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/CustomPropertiesPanel.vue` | `loadData()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/DecisionsSection.vue` | `loadData()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/DocumentAssessmentPanel.vue` | `getAssessment()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/DocumentChecklist.vue` | `loadData()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/EmailComposer.vue` | `onTemplateSelected()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/EmailComposer.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/EmailThread.vue` | `formatDateTime()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/EmailThread.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/EmailThread.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/EnforcementPanel.vue` | `statusLabel()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/EnforcementWizard.vue` | `submit()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/InspectionChecklistPanel.vue` | `startInspection()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/InspectionPanel.vue` | `resultLabel()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/LocationTab.vue` | `loadAddress()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/LocationTab.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/MilestoneProgress.vue` | `stepClass()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/ParticipantsSection.vue` | `fetchData()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/QuickStatusDropdown.vue` | `onStatusChange()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/QuickStatusDropdown.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/ResultSection.vue` | `formatPeriod()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/ResultSection.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/ResultSection.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/ResultSection.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/ShareTab.vue` | `permissionLabel()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/StatusTimeline.vue` | `isPassed()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/SubCasesSection.vue` | `fetchSubCases()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/SubCasesSection.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/SubCasesSection.vue` | `for()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/TenantSwitcher.vue` | `switchTenant()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/TenantSwitcher.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/VoorstellenPanel.vue` | `loadVoorstellen()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/WooIntakeForm.vue` | `update()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/WorkflowTransitions.vue` | `loadWorkflow()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/WorkflowTransitions.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/WorkflowTransitions.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/beroep/BeroepEscalationPanel.vue` | `escalate()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/beroep/CourtProceedingsPanel.vue` | `getRulingLabel()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/bezwaar/AdvisoryReportPanel.vue` | `getAdviceTypeLabel()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/bezwaar/BezwaarDecisionForm.vue` | `getDispositionLabel()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/bezwaar/BezwaarIntakeForm.vue` | `loadExistingObjection()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/bezwaar/BezwaarIntakeForm.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/bezwaar/BezwaarIntakeForm.vue` | `if()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/bezwaar/BezwaarTimeline.vue` | `getAdviceTypeLabel()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/cases/components/bezwaar/HearingPanel.vue` | `getHearingStatusLabel()` | REQ-CM-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: case-map-overview — 5 methods across 2 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `src/views/CaseMapView.vue` | `loadData()` | REQ-OVERVIEW-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/dashboard/CaseMapWidget.vue` | `getColor()` | REQ-OVERVIEW-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/dashboard/CaseMapWidget.vue` | `if()` | REQ-OVERVIEW-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/dashboard/CaseMapWidget.vue` | `if()` | REQ-OVERVIEW-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/dashboard/CaseMapWidget.vue` | `if()` | REQ-OVERVIEW-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: case-types — 5 methods across 1 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `src/utils/caseTypeValidation.js` | `getOriginOptions()` | REQ-CT-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/caseTypeValidation.js` | `getConfidentialityOptions()` | REQ-CT-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/caseTypeValidation.js` | `validateCaseType()` | REQ-CT-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/caseTypeValidation.js` | `validateForPublish()` | REQ-CT-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/caseTypeValidation.js` | `getFieldLabel()` | REQ-CT-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: dashboard — 18 methods across 6 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `src/utils/dashboardHelpers.js` | `todayString()` | REQ-DASH-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/dashboardHelpers.js` | `computeKpis()` | REQ-DASH-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/dashboardHelpers.js` | `aggregateByStatus()` | REQ-DASH-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/dashboardHelpers.js` | `getOverdueCases()` | REQ-DASH-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/dashboardHelpers.js` | `getRecentActivity()` | REQ-DASH-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/dashboardHelpers.js` | `getMyWorkItems()` | REQ-DASH-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/dashboardHelpers.js` | `endOfWeek()` | REQ-DASH-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/dashboardHelpers.js` | `getGroupedMyWorkItems()` | REQ-DASH-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/dashboardHelpers.js` | `getDeadlineAlerts()` | REQ-DASH-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/dashboardHelpers.js` | `getTaskDueReminders()` | REQ-DASH-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/dashboardHelpers.js` | `getStalledCases()` | REQ-DASH-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/dashboardHelpers.js` | `formatRelativeTime()` | REQ-DASH-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/Dashboard.vue` | `loadDashboardData()` | REQ-DASH-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/Dashboard.vue` | `for()` | REQ-DASH-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/dashboard/ActivityFeed.vue` | `typeIcon()` | REQ-DASH-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/dashboard/StatusChart.vue` | `barWidth()` | REQ-DASH-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/widgets/CasesOverviewWidget.vue` | `onShow()` | REQ-DASH-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/widgets/StartCaseWidget.vue` | `fetchCaseTypes()` | REQ-DASH-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: doorlooptijd-dashboard — 25 methods across 5 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Controller/MilestoneController.php` | `__construct()` | REQ-006 | 0.8 ⚠️ NEEDS-REVIEW | explicit: Milestone endpoints |
| `lib/Controller/MilestoneController.php` | `progress()` | REQ-006 | 0.8 ⚠️ NEEDS-REVIEW | explicit: Milestone endpoints |
| `lib/Controller/MilestoneController.php` | `mark()` | REQ-006 | 0.8 ⚠️ NEEDS-REVIEW | explicit: Milestone endpoints |
| `lib/Controller/MilestoneController.php` | `reverse()` | REQ-006 | 0.8 ⚠️ NEEDS-REVIEW | explicit: Milestone endpoints |
| `lib/Service/MilestoneService.php` | `__construct()` | REQ-006 | 0.8 ⚠️ NEEDS-REVIEW | explicit: Milestones = doorloop milestones |
| `lib/Service/MilestoneService.php` | `getMilestones()` | REQ-006 | 0.8 ⚠️ NEEDS-REVIEW | explicit: Milestones = doorloop milestones |
| `lib/Service/MilestoneService.php` | `getCaseProgress()` | REQ-006 | 0.8 ⚠️ NEEDS-REVIEW | explicit: Milestones = doorloop milestones |
| `lib/Service/MilestoneService.php` | `markMilestone()` | REQ-006 | 0.8 ⚠️ NEEDS-REVIEW | explicit: Milestones = doorloop milestones |
| `lib/Service/MilestoneService.php` | `reverseMilestone()` | REQ-006 | 0.8 ⚠️ NEEDS-REVIEW | explicit: Milestones = doorloop milestones |
| `lib/Service/MilestoneService.php` | `getDurationAnalytics()` | REQ-006 | 0.8 ⚠️ NEEDS-REVIEW | explicit: Milestones = doorloop milestones |
| `lib/Service/MilestoneService.php` | `getMilestoneRecords()` | REQ-006 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `src/utils/doorlooptijdHelpers.js` | `parseDurationToDays()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/doorlooptijdHelpers.js` | `getProcessingDays()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/doorlooptijdHelpers.js` | `getSlaTargetDays()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/doorlooptijdHelpers.js` | `buildCaseTypeMap()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/doorlooptijdHelpers.js` | `computeSlaCompliance()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/doorlooptijdHelpers.js` | `computeProcessingTimeDistribution()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/doorlooptijdHelpers.js` | `computeMonthlyTrend()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/doorlooptijdHelpers.js` | `getAtRiskCases()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/doorlooptijdHelpers.js` | `computePerformanceTable()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/durationHelpers.js` | `isValidDuration()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/durationHelpers.js` | `parseDuration()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/durationHelpers.js` | `formatDuration()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/durationHelpers.js` | `getDurationError()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/DoorlooptijdDashboard.vue` | `loadData()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: inspection-checklists — 18 methods across 3 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Controller/InspectionController.php` | `__construct()` | REQ-001 | 0.83 ⚠️ NEEDS-REVIEW | explicit: Inspection controller methods |
| `lib/Controller/InspectionController.php` | `index()` | REQ-001 | 0.83 ⚠️ NEEDS-REVIEW | explicit: Inspection controller methods |
| `lib/Controller/InspectionController.php` | `captureLocation()` | REQ-001 | 0.83 ⚠️ NEEDS-REVIEW | explicit: Inspection controller methods |
| `lib/Controller/InspectionController.php` | `completeChecklistItem()` | REQ-001 | 0.83 ⚠️ NEEDS-REVIEW | explicit: Inspection controller methods |
| `lib/Controller/InspectionController.php` | `addPhoto()` | REQ-001 | 0.83 ⚠️ NEEDS-REVIEW | explicit: Inspection controller methods |
| `lib/Controller/InspectionController.php` | `complete()` | REQ-001 | 0.83 ⚠️ NEEDS-REVIEW | explicit: Inspection controller methods |
| `lib/Controller/InspectionController.php` | `getRequestBody()` | REQ-001 | 0.73 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ChecklistService.php` | `__construct()` | REQ-001 | 0.82 ⚠️ NEEDS-REVIEW | explicit: Checklist validation and progress |
| `lib/Service/ChecklistService.php` | `completeItem()` | REQ-001 | 0.82 ⚠️ NEEDS-REVIEW | explicit: Checklist validation and progress |
| `lib/Service/ChecklistService.php` | `getProgress()` | REQ-001 | 0.82 ⚠️ NEEDS-REVIEW | explicit: Checklist validation and progress |
| `lib/Service/ChecklistService.php` | `validateCompletion()` | REQ-001 | 0.82 ⚠️ NEEDS-REVIEW | explicit: Checklist validation and progress |
| `lib/Service/ChecklistService.php` | `getConformitySummary()` | REQ-001 | 0.82 ⚠️ NEEDS-REVIEW | explicit: Checklist validation and progress |
| `lib/Service/InspectionService.php` | `__construct()` | REQ-003 | 0.83 ⚠️ NEEDS-REVIEW | explicit: Inspection service methods |
| `lib/Service/InspectionService.php` | `getInspections()` | REQ-003 | 0.83 ⚠️ NEEDS-REVIEW | explicit: Inspection service methods |
| `lib/Service/InspectionService.php` | `captureLocation()` | REQ-003 | 0.83 ⚠️ NEEDS-REVIEW | explicit: Inspection service methods |
| `lib/Service/InspectionService.php` | `addPhoto()` | REQ-003 | 0.83 ⚠️ NEEDS-REVIEW | explicit: Inspection service methods |
| `lib/Service/InspectionService.php` | `completeInspection()` | REQ-003 | 0.83 ⚠️ NEEDS-REVIEW | explicit: Inspection service methods |
| `lib/Service/InspectionService.php` | `calculateDistance()` | REQ-003 | 0.73 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |

### capability: map-component — 9 methods across 5 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `src/components/map/AddressSearch.vue` | `onInput()` | REQ-MAP-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/components/map/AddressSearch.vue` | `if()` | REQ-MAP-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/components/map/CaseMap.vue` | `initMap()` | REQ-MAP-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/components/map/LocationPicker.vue` | `initMap()` | REQ-MAP-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/components/map/LocationPicker.vue` | `if()` | REQ-MAP-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/components/map/LocationPicker.vue` | `if()` | REQ-MAP-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/components/map/MapLayerSwitcher.vue` | `toggleLayer()` | REQ-MAP-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/components/map/MapLayerSwitcher.vue` | `if()` | REQ-MAP-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/components/map/SpatialFilter.vue` | `initDrawLayer()` | REQ-MAP-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: my-work — 5 methods across 3 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `src/views/MyWork.vue` | `getCaseTypeName()` | REQ-MYWORK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/Werkvoorraad.vue` | `loadData()` | REQ-MYWORK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/Werkvoorraad.vue` | `if()` | REQ-MYWORK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/Werkvoorraad.vue` | `if()` | REQ-MYWORK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/widgets/MyTasksWidget.vue` | `onShow()` | REQ-MYWORK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: openregister-integration — 2 methods across 1 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `src/utils/openregisterCheck.js` | `checkOpenRegisterStatus()` | REQ-OREG-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/openregisterCheck.js` | `getStatusMessage()` | REQ-OREG-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: parafeerroute-engine — 9 methods across 1 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `src/utils/parafeerEngine.js` | `getRouteSteps()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/parafeerEngine.js` | `getCurrentStep()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/parafeerEngine.js` | `isActiveActor()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/parafeerEngine.js` | `getNextStep()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/parafeerEngine.js` | `getStatusAfterAdvance()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/parafeerEngine.js` | `createRouteSnapshot()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/parafeerEngine.js` | `insertAdHocStep()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/parafeerEngine.js` | `markStepSkipped()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/parafeerEngine.js` | `findDefaultRoute()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: parafering-actions — 15 methods across 3 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Controller/ParaferingController.php` | `__construct()` | REQ-002 | 0.78 ⚠️ NEEDS-REVIEW | explicit: Parafering controller |
| `lib/Controller/ParaferingController.php` | `startParafering()` | REQ-002 | 0.85 | explicit: Parafering route start |
| `lib/Controller/ParaferingController.php` | `paraferen()` | REQ-002 | 0.78 ⚠️ NEEDS-REVIEW | explicit: Parafering controller |
| `lib/Controller/ParaferingController.php` | `terugsturen()` | REQ-002 | 0.78 ⚠️ NEEDS-REVIEW | explicit: Parafering controller |
| `lib/Controller/ParaferingController.php` | `adviseren()` | REQ-002 | 0.78 ⚠️ NEEDS-REVIEW | explicit: Parafering controller |
| `lib/Controller/ParaferingController.php` | `auditTrail()` | REQ-002 | 0.78 ⚠️ NEEDS-REVIEW | explicit: Parafering controller |
| `lib/Service/ParaferingNotificationService.php` | `__construct()` | REQ-002 | 0.78 ⚠️ NEEDS-REVIEW | explicit: Parafering notifications — NEEDS-REVIEW specific REQ |
| `lib/Service/ParaferingNotificationService.php` | `notifyStepActivated()` | REQ-002 | 0.78 ⚠️ NEEDS-REVIEW | explicit: Parafering notifications — NEEDS-REVIEW specific REQ |
| `lib/Service/ParaferingNotificationService.php` | `notifyVoorstelReturned()` | REQ-002 | 0.78 ⚠️ NEEDS-REVIEW | explicit: Parafering notifications — NEEDS-REVIEW specific REQ |
| `lib/Service/ParaferingNotificationService.php` | `notifyParaferingReminder()` | REQ-002 | 0.78 ⚠️ NEEDS-REVIEW | explicit: Parafering notifications — NEEDS-REVIEW specific REQ |
| `lib/Service/ParaferingService.php` | `__construct()` | REQ-002 | 0.78 ⚠️ NEEDS-REVIEW | explicit: Parafering service — specific REQ per method deferred |
| `lib/Service/ParaferingService.php` | `startParafering()` | REQ-002 | 0.85 | explicit: Parafering route start |
| `lib/Service/ParaferingService.php` | `executeAction()` | REQ-002 | 0.85 | explicit: Parafering action execution |
| `lib/Service/ParaferingService.php` | `getCurrentStep()` | REQ-002 | 0.78 ⚠️ NEEDS-REVIEW | explicit: Parafering service — specific REQ per method deferred |
| `lib/Service/ParaferingService.php` | `overrideRoute()` | REQ-002 | 0.78 ⚠️ NEEDS-REVIEW | explicit: Parafering service — specific REQ per method deferred |

### capability: parafering-audit-trail — 1 methods across 1 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Service/ParaferingService.php` | `getAuditTrail()` | REQ-001 | 0.9 | explicit: Audit trail query |

### capability: pdok-integration — 11 methods across 2 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `src/services/coordinateService.js` | `isRDCoordinate()` | REQ-PDOK-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/services/coordinateService.js` | `rdToWgs84()` | REQ-PDOK-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/services/coordinateService.js` | `wgs84ToRd()` | REQ-PDOK-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/services/coordinateService.js` | `ensureWgs84()` | REQ-PDOK-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/services/coordinateService.js` | `convertCoordinates()` | REQ-PDOK-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/services/pdokService.js` | `suggest()` | REQ-PDOK-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/services/pdokService.js` | `lookup()` | REQ-PDOK-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/services/pdokService.js` | `free()` | REQ-PDOK-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/services/pdokService.js` | `reverse()` | REQ-PDOK-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/services/pdokService.js` | `extractCoordinates()` | REQ-PDOK-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/services/pdokService.js` | `formatAddress()` | REQ-PDOK-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: procest-app-scaffold — 13 methods across 6 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/AppInfo/Application.php` | `register()` | REQ-001 | 0.95 | explicit: Application::register = NC app registration boot (REQ-001) |
| `lib/AppInfo/Application.php` | `boot()` | REQ-001 | 0.95 | explicit: Application::boot = NC app boot hook |
| `lib/Controller/DashboardController.php` | `page()` | REQ-002 | 0.93 | explicit: Vue SPA entry point (REQ-002) |
| `lib/Repair/InitializeSettings.php` | `__construct()` | REQ-006 | 0.92 | explicit: Settings initialization on install |
| `lib/Repair/InitializeSettings.php` | `getName()` | REQ-006 | 0.92 | explicit: Settings initialization on install |
| `lib/Repair/InitializeSettings.php` | `run()` | REQ-006 | 0.92 | explicit: Settings initialization on install |
| `src/App.vue` | `onSidebarSearch()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/App.vue` | `if()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/navigation/MainMenu.vue` | `openLink()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/i18nResolver.js` | `getUserLocale()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/i18nResolver.js` | `resolveTranslatable()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/i18nResolver.js` | `resolveField()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/i18nResolver.js` | `resolveText()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: procest-object-store — 5 methods across 3 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `src/store/modules/bezwaar.js` | `addWorkingDays()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/store/modules/bezwaar.js` | `addWeeks()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/store/modules/bezwaar.js` | `daysDifference()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/store/modules/workflow.js` | `generateUUID()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/store/store.js` | `initializeStores()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: prometheus-metrics — 19 methods across 2 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Controller/HealthController.php` | `__construct()` | REQ-PROM-004 | 0.83 ⚠️ NEEDS-REVIEW | explicit: Health endpoint helpers |
| `lib/Controller/HealthController.php` | `index()` | REQ-PROM-004 | 0.97 | explicit: /health endpoint |
| `lib/Controller/HealthController.php` | `checkDatabase()` | REQ-PROM-004 | 0.87 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/HealthController.php` | `checkOpenRegister()` | REQ-PROM-004 | 0.87 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/HealthController.php` | `checkFilesystem()` | REQ-PROM-004 | 0.87 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/HealthController.php` | `getAppVersion()` | REQ-PROM-004 | 0.87 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/MetricsController.php` | `__construct()` | REQ-PROM-001 | 0.83 ⚠️ NEEDS-REVIEW | explicit: Metrics endpoint helpers — private fall back here via Pass B |
| `lib/Controller/MetricsController.php` | `index()` | REQ-PROM-001 | 0.97 | explicit: /metrics endpoint |
| `lib/Controller/MetricsController.php` | `collectMetrics()` | REQ-PROM-001 | 0.87 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/MetricsController.php` | `getCached()` | REQ-PROM-001 | 0.87 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/MetricsController.php` | `checkDatabaseHealth()` | REQ-PROM-001 | 0.87 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/MetricsController.php` | `getCaseCounts()` | REQ-PROM-001 | 0.87 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/MetricsController.php` | `getOverdueCasesCount()` | REQ-PROM-001 | 0.87 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/MetricsController.php` | `getCasesCreatedTodayCount()` | REQ-PROM-001 | 0.87 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/MetricsController.php` | `getTaskCounts()` | REQ-PROM-001 | 0.87 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/MetricsController.php` | `getOverdueTasksCount()` | REQ-PROM-001 | 0.87 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/MetricsController.php` | `getAppVersion()` | REQ-PROM-001 | 0.87 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/MetricsController.php` | `getNextcloudVersion()` | REQ-PROM-001 | 0.87 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/MetricsController.php` | `sanitizeLabel()` | REQ-PROM-001 | 0.87 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |

### capability: roles-decisions — 3 methods across 1 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `src/utils/decisionHelpers.js` | `getDecisionValidity()` | REQ-ROLE-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/decisionHelpers.js` | `formatDecisionDate()` | REQ-ROLE-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/decisionHelpers.js` | `validateDecision()` | REQ-ROLE-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: signalering-widgets — 7 methods across 5 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `src/views/dashboard/OverduePanel.vue` | `severityClass()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/dashboard/OverduePanel.vue` | `if()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/dashboard/OverduePanel.vue` | `if()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/widgets/DeadlineAlertsWidget.vue` | `onShow()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/widgets/OverdueCasesWidget.vue` | `onShow()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/widgets/StalledCasesWidget.vue` | `onShow()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/widgets/TaskRemindersWidget.vue` | `onShow()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: task-management — 27 methods across 7 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `src/services/taskApi.js` | `getHeaders()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/services/taskApi.js` | `mapCalDavPriority()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/services/taskApi.js` | `normalizeCalDavTask()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/services/taskApi.js` | `fetchTasksForObject()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/services/taskApi.js` | `fetchTasksForCases()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/taskHelpers.js` | `getPriorityLevels()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/taskHelpers.js` | `isOverdue()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/taskHelpers.js` | `isDueToday()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/taskHelpers.js` | `getOverdueText()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/taskHelpers.js` | `formatDueDate()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/taskHelpers.js` | `prioritySortWeight()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/taskHelpers.js` | `statusGroupWeight()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/taskHelpers.js` | `sortTasks()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/taskLifecycle.js` | `getStatusLabels()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/taskLifecycle.js` | `getTransitionLabels()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/taskLifecycle.js` | `getAllowedTransitions()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/taskLifecycle.js` | `validateTransition()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/taskLifecycle.js` | `getStatusLabel()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/taskLifecycle.js` | `getTransitionLabel()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/taskLifecycle.js` | `isTerminalStatus()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/taskValidation.js` | `validateTaskCreate()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/taskValidation.js` | `validateTaskUpdate()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/utils/taskValidation.js` | `validateTaskTransition()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/tasks/TaskCreateDialog.vue` | `submit()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/tasks/TaskCreateDialog.vue` | `if()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/tasks/TaskDetail.vue` | `startEditing()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/tasks/TaskList.vue` | `getPriorityLabel()` | REQ-TASK-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: voorstel-management — 17 methods across 10 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Controller/ParaferingController.php` | `createVoorstel()` | REQ-002 | 0.9 | explicit: Create voorstel from case |
| `lib/Controller/ParaferingController.php` | `handleAction()` | REQ-002 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ParaferingController.php` | `getRequestBody()` | REQ-002 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ParaferingService.php` | `createVoorstel()` | REQ-002 | 0.9 | explicit: Create voorstel from case |
| `lib/Service/ParaferingService.php` | `advanceStep()` | REQ-002 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ParaferingService.php` | `handleParallelStep()` | REQ-002 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ParaferingService.php` | `generateId()` | REQ-002 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `src/views/voorstellen/VoorstelDetail.vue` | `loadVoorstel()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/voorstellen/VoorstelList.vue` | `loadVoorstellen()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/voorstellen/components/AuditTrail.vue` | `formatAction()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/voorstellen/components/BesluitRegistration.vue` | `register()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/voorstellen/components/BesluitRegistration.vue` | `if()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/voorstellen/components/ParafeerActionBar.vue` | `formatStepType()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/voorstellen/components/ParafeerInbox.vue` | `loadVoorstellen()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/voorstellen/components/ProgressTimeline.vue` | `isCompleted()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/voorstellen/components/VoorstelCreateDialog.vue` | `onCaseSelected()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/views/voorstellen/components/VoorstelCreateDialog.vue` | `if()` | REQ-001 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: vth-case-type-seed — 13 methods across 2 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Repair/SeedBezwaarBeroepData.php` | `__construct()` | REQ-001 | 0.92 | explicit: Seed repair step |
| `lib/Repair/SeedBezwaarBeroepData.php` | `getName()` | REQ-001 | 0.92 | explicit: Seed repair step |
| `lib/Repair/SeedBezwaarBeroepData.php` | `run()` | REQ-001 | 0.92 | explicit: Seed repair step |
| `lib/Service/SeedDataService.php` | `__construct()` | REQ-001 | 0.85 | explicit: Seed data service |
| `lib/Service/SeedDataService.php` | `seedBezwaarBeroepData()` | REQ-001 | 0.85 | explicit: Seed data service |
| `lib/Service/SeedDataService.php` | `seedCaseType()` | REQ-001 | 0.75 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/SeedDataService.php` | `resolveWorkflowReferences()` | REQ-001 | 0.75 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/SeedDataService.php` | `createObject()` | REQ-001 | 0.75 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/SeedDataService.php` | `findByFilter()` | REQ-001 | 0.75 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/SeedDataService.php` | `getObjectService()` | REQ-001 | 0.75 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/SeedDataService.php` | `getConfigValue()` | REQ-001 | 0.75 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/SeedDataService.php` | `getObjectId()` | REQ-001 | 0.75 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/SeedDataService.php` | `generateUUID()` | REQ-001 | 0.75 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |

### capability: wms-wfs-layers — 8 methods across 3 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Controller/GisProxyController.php` | `proxy()` | REQ-LAYER-03 | 0.93 | name+path keyword match |
| `lib/Service/GisProxyService.php` | `proxyRequest()` | REQ-LAYER-03 | 0.93 | name+path keyword match |
| `lib/Service/GisProxyService.php` | `isUrlAllowed()` | REQ-LAYER-03 | 0.83 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/GisProxyService.php` | `checkRateLimit()` | REQ-LAYER-03 | 0.83 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/GisProxyService.php` | `parseCapabilities()` | REQ-LAYER-03 | 0.83 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/GisProxyService.php` | `xmlToArray()` | REQ-LAYER-03 | 0.83 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `src/services/gisProxyService.js` | `proxyRequest()` | REQ-LAYER-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |
| `src/services/gisProxyService.js` | `getCapabilities()` | REQ-LAYER-01 | 0.78 ⚠️ NEEDS-REVIEW | frontend path-based capability match; specific REQ per method deferred |

### capability: zgw-api-mapping — 236 methods across 12 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Controller/AcController.php` | `__construct()` | REQ-007 | 0.85 | explicit: AcController=AC API |
| `lib/Controller/AcController.php` | `index()` | REQ-007 | 0.85 | explicit: AcController=AC API |
| `lib/Controller/AcController.php` | `create()` | REQ-007 | 0.85 | explicit: AcController=AC API |
| `lib/Controller/AcController.php` | `show()` | REQ-007 | 0.85 | explicit: AcController=AC API |
| `lib/Controller/AcController.php` | `update()` | REQ-007 | 0.85 | explicit: AcController=AC API |
| `lib/Controller/AcController.php` | `patch()` | REQ-007 | 0.85 | explicit: AcController=AC API |
| `lib/Controller/AcController.php` | `destroy()` | REQ-007 | 0.85 | explicit: AcController=AC API |
| `lib/Controller/AcController.php` | `findConsumerByUuid()` | REQ-007 | 0.75 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/AcController.php` | `validateApplicatieBody()` | REQ-007 | 0.75 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/AcController.php` | `validateClientIdUniqueness()` | REQ-007 | 0.75 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/AcController.php` | `validateAutorisatieConsistency()` | REQ-007 | 0.75 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/AcController.php` | `validateAutorisatieScopes()` | REQ-007 | 0.75 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/AcController.php` | `scopesContain()` | REQ-007 | 0.75 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/AcController.php` | `getConsumerClientIds()` | REQ-007 | 0.75 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/AcController.php` | `consumerToApplicatie()` | REQ-007 | 0.75 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/AcController.php` | `applicatieToConsumer()` | REQ-007 | 0.75 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/BrcController.php` | `__construct()` | REQ-006 | 0.92 | explicit: BrcController=BRC API, REQ-006 covers BRC mappability |
| `lib/Controller/BrcController.php` | `index()` | REQ-006 | 0.92 | explicit: BrcController=BRC API, REQ-006 covers BRC mappability |
| `lib/Controller/BrcController.php` | `create()` | REQ-006 | 0.92 | explicit: BrcController=BRC API, REQ-006 covers BRC mappability |
| `lib/Controller/BrcController.php` | `show()` | REQ-006 | 0.92 | explicit: BrcController=BRC API, REQ-006 covers BRC mappability |
| `lib/Controller/BrcController.php` | `update()` | REQ-006 | 0.92 | explicit: BrcController=BRC API, REQ-006 covers BRC mappability |
| `lib/Controller/BrcController.php` | `patch()` | REQ-006 | 0.92 | explicit: BrcController=BRC API, REQ-006 covers BRC mappability |
| `lib/Controller/BrcController.php` | `destroy()` | REQ-006 | 0.92 | explicit: BrcController=BRC API, REQ-006 covers BRC mappability |
| `lib/Controller/BrcController.php` | `audittrailIndex()` | REQ-006 | 0.92 | explicit: BrcController=BRC API, REQ-006 covers BRC mappability |
| `lib/Controller/BrcController.php` | `audittrailShow()` | REQ-006 | 0.92 | explicit: BrcController=BRC API, REQ-006 covers BRC mappability |
| `lib/Controller/BrcController.php` | `createBesluitWithZaakSync()` | REQ-006 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/BrcController.php` | `syncZaakBesluitToZrc()` | REQ-006 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/BrcController.php` | `indexBesluitInformatieObjecten()` | REQ-006 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/BrcController.php` | `createBesluitInformatieObject()` | REQ-006 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/BrcController.php` | `createOioInDrc()` | REQ-006 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/BrcController.php` | `deleteOiosForBesluit()` | REQ-006 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/BrcController.php` | `destroyBesluitInformatieObject()` | REQ-006 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/BrcController.php` | `deleteOioByBesluitAndIo()` | REQ-006 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/BrcController.php` | `destroyBesluit()` | REQ-006 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/DrcController.php` | `__construct()` | REQ-005 | 0.92 | explicit: DrcController=DRC API, REQ-005 covers DRC mappability |
| `lib/Controller/DrcController.php` | `index()` | REQ-005 | 0.92 | explicit: DrcController=DRC API, REQ-005 covers DRC mappability |
| `lib/Controller/DrcController.php` | `create()` | REQ-005 | 0.92 | explicit: DrcController=DRC API, REQ-005 covers DRC mappability |
| `lib/Controller/DrcController.php` | `show()` | REQ-005 | 0.92 | explicit: DrcController=DRC API, REQ-005 covers DRC mappability |
| `lib/Controller/DrcController.php` | `update()` | REQ-005 | 0.92 | explicit: DrcController=DRC API, REQ-005 covers DRC mappability |
| `lib/Controller/DrcController.php` | `patch()` | REQ-005 | 0.92 | explicit: DrcController=DRC API, REQ-005 covers DRC mappability |
| `lib/Controller/DrcController.php` | `destroy()` | REQ-005 | 0.92 | explicit: DrcController=DRC API, REQ-005 covers DRC mappability |
| `lib/Controller/DrcController.php` | `download()` | REQ-005 | 0.92 | explicit: DrcController=DRC API, REQ-005 covers DRC mappability |
| `lib/Controller/DrcController.php` | `lock()` | REQ-005 | 0.92 | explicit: DrcController=DRC API, REQ-005 covers DRC mappability |
| `lib/Controller/DrcController.php` | `unlock()` | REQ-005 | 0.92 | explicit: DrcController=DRC API, REQ-005 covers DRC mappability |
| `lib/Controller/DrcController.php` | `audittrailIndex()` | REQ-005 | 0.92 | explicit: DrcController=DRC API, REQ-005 covers DRC mappability |
| `lib/Controller/DrcController.php` | `audittrailShow()` | REQ-005 | 0.92 | explicit: DrcController=DRC API, REQ-005 covers DRC mappability |
| `lib/Controller/DrcController.php` | `uploadChunk()` | REQ-005 | 0.92 | explicit: DrcController=DRC API, REQ-005 covers DRC mappability |
| `lib/Controller/DrcController.php` | `indexFlatArray()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/DrcController.php` | `lockFallback()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/DrcController.php` | `unlockFallback()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/DrcController.php` | `findOioRelationsForEio()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/DrcController.php` | `searchRelationsInSchema()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/DrcController.php` | `extractIdsFromResults()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/DrcController.php` | `cascadeDeleteGebruiksrechten()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/DrcController.php` | `updateIndicatieGebruiksrecht()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/DrcController.php` | `getGebruiksrechtData()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/DrcController.php` | `checkAndClearIndicatieGebruiksrecht()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/DrcController.php` | `setIndicatieGebruiksrecht()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/DrcController.php` | `enrichWithBestandsdelen()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/DrcController.php` | `parseFileParts()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/DrcController.php` | `buildBestandsdelenArray()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/DrcController.php` | `handleEioUpdate()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/DrcController.php` | `checkDocumentLock()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/DrcController.php` | `resolveStoredLockId()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/DrcController.php` | `storeLockIdInData()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/DrcController.php` | `clearLockIdInData()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/NrcController.php` | `__construct()` | REQ-008 | 0.85 | explicit: NrcController=NRC API |
| `lib/Controller/NrcController.php` | `index()` | REQ-008 | 0.85 | explicit: NrcController=NRC API |
| `lib/Controller/NrcController.php` | `create()` | REQ-008 | 0.85 | explicit: NrcController=NRC API |
| `lib/Controller/NrcController.php` | `show()` | REQ-008 | 0.85 | explicit: NrcController=NRC API |
| `lib/Controller/NrcController.php` | `update()` | REQ-008 | 0.85 | explicit: NrcController=NRC API |
| `lib/Controller/NrcController.php` | `patch()` | REQ-008 | 0.85 | explicit: NrcController=NRC API |
| `lib/Controller/NrcController.php` | `destroy()` | REQ-008 | 0.85 | explicit: NrcController=NRC API |
| `lib/Controller/NrcController.php` | `notificatieCreate()` | REQ-008 | 0.85 | explicit: NrcController=NRC API |
| `lib/Controller/NrcController.php` | `audittrailIndex()` | REQ-008 | 0.85 | explicit: NrcController=NRC API |
| `lib/Controller/NrcController.php` | `audittrailShow()` | REQ-008 | 0.85 | explicit: NrcController=NRC API |
| `lib/Controller/ZgwMappingController.php` | `__construct()` | REQ-001 | 0.85 | explicit: ZGW mapping admin endpoints |
| `lib/Controller/ZgwMappingController.php` | `index()` | REQ-001 | 0.85 | explicit: ZGW mapping admin endpoints |
| `lib/Controller/ZgwMappingController.php` | `show()` | REQ-001 | 0.85 | explicit: ZGW mapping admin endpoints |
| `lib/Controller/ZgwMappingController.php` | `update()` | REQ-001 | 0.85 | explicit: ZGW mapping admin endpoints |
| `lib/Controller/ZgwMappingController.php` | `destroy()` | REQ-001 | 0.85 | explicit: ZGW mapping admin endpoints |
| `lib/Controller/ZgwMappingController.php` | `reset()` | REQ-001 | 0.85 | explicit: ZGW mapping admin endpoints |
| `lib/Controller/ZrcController.php` | `__construct()` | REQ-003 | 0.92 | explicit: ZrcController=ZRC API, REQ-003 covers ZRC mappability |
| `lib/Controller/ZrcController.php` | `index()` | REQ-003 | 0.92 | explicit: ZrcController=ZRC API, REQ-003 covers ZRC mappability |
| `lib/Controller/ZrcController.php` | `create()` | REQ-003 | 0.92 | explicit: ZrcController=ZRC API, REQ-003 covers ZRC mappability |
| `lib/Controller/ZrcController.php` | `show()` | REQ-003 | 0.92 | explicit: ZrcController=ZRC API, REQ-003 covers ZRC mappability |
| `lib/Controller/ZrcController.php` | `update()` | REQ-003 | 0.92 | explicit: ZrcController=ZRC API, REQ-003 covers ZRC mappability |
| `lib/Controller/ZrcController.php` | `patch()` | REQ-003 | 0.92 | explicit: ZrcController=ZRC API, REQ-003 covers ZRC mappability |
| `lib/Controller/ZrcController.php` | `destroy()` | REQ-003 | 0.92 | explicit: ZrcController=ZRC API, REQ-003 covers ZRC mappability |
| `lib/Controller/ZrcController.php` | `zaakeigenschappenIndex()` | REQ-003 | 0.92 | explicit: ZrcController=ZRC API, REQ-003 covers ZRC mappability |
| `lib/Controller/ZrcController.php` | `zaakeigenschappenCreate()` | REQ-003 | 0.92 | explicit: ZrcController=ZRC API, REQ-003 covers ZRC mappability |
| `lib/Controller/ZrcController.php` | `zaakeigenschappenShow()` | REQ-003 | 0.92 | explicit: ZrcController=ZRC API, REQ-003 covers ZRC mappability |
| `lib/Controller/ZrcController.php` | `zaakeigenschappenUpdate()` | REQ-003 | 0.92 | explicit: ZrcController=ZRC API, REQ-003 covers ZRC mappability |
| `lib/Controller/ZrcController.php` | `zaakeigenschappenPatch()` | REQ-003 | 0.92 | explicit: ZrcController=ZRC API, REQ-003 covers ZRC mappability |
| `lib/Controller/ZrcController.php` | `zaakeigenschappenDestroy()` | REQ-003 | 0.92 | explicit: ZrcController=ZRC API, REQ-003 covers ZRC mappability |
| `lib/Controller/ZrcController.php` | `zaakbesluitenIndex()` | REQ-003 | 0.92 | explicit: ZrcController=ZRC API, REQ-003 covers ZRC mappability |
| `lib/Controller/ZrcController.php` | `zoek()` | REQ-003 | 0.92 | explicit: ZrcController=ZRC API, REQ-003 covers ZRC mappability |
| `lib/Controller/ZrcController.php` | `audittrailIndex()` | REQ-003 | 0.92 | explicit: ZrcController=ZRC API, REQ-003 covers ZRC mappability |
| `lib/Controller/ZrcController.php` | `audittrailShow()` | REQ-003 | 0.92 | explicit: ZrcController=ZRC API, REQ-003 covers ZRC mappability |
| `lib/Controller/ZrcController.php` | `checkZaakReadAccess()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `filterZakenByAuthorisation()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `permissionDeniedResponse()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `preValidateZaakBody()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `preValidateProductenOfDiensten()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `destroyZaak()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `resolveZaakClosedForExisting()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `checkReopenScope()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `checkIndicatieGebruiksrechtBeforeClose()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `isEindstatusByVolgnummer()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `handleEindstatusEffect()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `setIndicatieGebruiksrechtOnClose()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `handleResultaatCreated()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `deriveArchiefactiedatum()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `resolveArchiveBaseDate()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `resolveEigenschapDate()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `resolveBesluitDate()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `enrichZioResponse()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `enrichZioJsonResponse()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `syncCreateObjectInformatieObject()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `getZioDataForOioSync()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZrcController.php` | `syncDeleteObjectInformatieObject()` | REQ-003 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZtcController.php` | `__construct()` | REQ-004 | 0.92 | explicit: ZtcController=ZTC API, REQ-004 covers ZTC mappability |
| `lib/Controller/ZtcController.php` | `index()` | REQ-004 | 0.92 | explicit: ZtcController=ZTC API, REQ-004 covers ZTC mappability |
| `lib/Controller/ZtcController.php` | `create()` | REQ-004 | 0.92 | explicit: ZtcController=ZTC API, REQ-004 covers ZTC mappability |
| `lib/Controller/ZtcController.php` | `show()` | REQ-004 | 0.92 | explicit: ZtcController=ZTC API, REQ-004 covers ZTC mappability |
| `lib/Controller/ZtcController.php` | `update()` | REQ-004 | 0.92 | explicit: ZtcController=ZTC API, REQ-004 covers ZTC mappability |
| `lib/Controller/ZtcController.php` | `patch()` | REQ-004 | 0.92 | explicit: ZtcController=ZTC API, REQ-004 covers ZTC mappability |
| `lib/Controller/ZtcController.php` | `destroy()` | REQ-004 | 0.92 | explicit: ZtcController=ZTC API, REQ-004 covers ZTC mappability |
| `lib/Controller/ZtcController.php` | `publishZaaktype()` | REQ-004 | 0.92 | explicit: ZtcController=ZTC API, REQ-004 covers ZTC mappability |
| `lib/Controller/ZtcController.php` | `publishBesluittype()` | REQ-004 | 0.92 | explicit: ZtcController=ZTC API, REQ-004 covers ZTC mappability |
| `lib/Controller/ZtcController.php` | `publishInformatieobjecttype()` | REQ-004 | 0.92 | explicit: ZtcController=ZTC API, REQ-004 covers ZTC mappability |
| `lib/Controller/ZtcController.php` | `audittrailIndex()` | REQ-004 | 0.92 | explicit: ZtcController=ZTC API, REQ-004 covers ZTC mappability |
| `lib/Controller/ZtcController.php` | `audittrailShow()` | REQ-004 | 0.92 | explicit: ZtcController=ZTC API, REQ-004 covers ZTC mappability |
| `lib/Controller/ZtcController.php` | `resolveParentDraft()` | REQ-004 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZtcController.php` | `handlePublish()` | REQ-004 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZtcController.php` | `enrichCrossReferences()` | REQ-004 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZtcController.php` | `enrichBesluittype()` | REQ-004 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZtcController.php` | `enrichZaaktype()` | REQ-004 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZtcController.php` | `filterByDatumGeldigheid()` | REQ-004 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZtcController.php` | `filterValidUrls()` | REQ-004 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZtcController.php` | `isUrlValid()` | REQ-004 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Controller/ZtcController.php` | `resolveIotByOmschrijving()` | REQ-004 | 0.82 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `__construct()` | REQ-001 | 0.9 | explicit: Seeds default ZGW mappings on install |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getName()` | REQ-001 | 0.9 | explicit: Seeds default ZGW mappings on install |
| `lib/Repair/LoadDefaultZgwMappings.php` | `run()` | REQ-001 | 0.9 | explicit: Seeds default ZGW mappings on install |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getDefaultMappings()` | REQ-001 | 0.9 | explicit: Seeds default ZGW mappings on install |
| `lib/Repair/LoadDefaultZgwMappings.php` | `patchExistingMappings()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `tplUrl()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getZaakMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getZaakTypeMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getStatusMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getStatusTypeMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getResultaatMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getResultaatTypeMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getRolMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getRolTypeMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getEigenschapMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getBesluitMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getBesluitTypeMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getInformatieObjectTypeMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getEnkelvoudigInformatieObjectMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getObjectInformatieObjectMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getGebruiksrechtenMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getKanaalMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getAbonnementMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getCatalogusMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getZaaktypeInformatieobjecttypeMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getApplicatieMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `createDefaultApplicaties()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getDefaultApplicaties()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `createDefaultKanalen()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getDefaultKanalen()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getZaakeigenschapMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getZaakinformatieobjectMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getZaakobjectMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getKlantcontactMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getBesluitinformatieobjectMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Repair/LoadDefaultZgwMappings.php` | `getVerzendingMapping()` | REQ-001 | 0.8 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwDocumentService.php` | `__construct()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | explicit: ZGW document handling (DRC mappability helpers) |
| `lib/Service/ZgwDocumentService.php` | `storeBase64()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | explicit: ZGW document handling (DRC mappability helpers) |
| `lib/Service/ZgwDocumentService.php` | `storeRaw()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | explicit: ZGW document handling (DRC mappability helpers) |
| `lib/Service/ZgwDocumentService.php` | `getContent()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | explicit: ZGW document handling (DRC mappability helpers) |
| `lib/Service/ZgwDocumentService.php` | `fileExists()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | explicit: ZGW document handling (DRC mappability helpers) |
| `lib/Service/ZgwDocumentService.php` | `deleteFiles()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | explicit: ZGW document handling (DRC mappability helpers) |
| `lib/Service/ZgwDocumentService.php` | `getMimeType()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | explicit: ZGW document handling (DRC mappability helpers) |
| `lib/Service/ZgwDocumentService.php` | `storeChunk()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | explicit: ZGW document handling (DRC mappability helpers) |
| `lib/Service/ZgwDocumentService.php` | `getUploadedChunks()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | explicit: ZGW document handling (DRC mappability helpers) |
| `lib/Service/ZgwDocumentService.php` | `mergeChunks()` | REQ-005 | 0.82 ⚠️ NEEDS-REVIEW | explicit: ZGW document handling (DRC mappability helpers) |
| `lib/Service/ZgwDocumentService.php` | `getDocumentFolder()` | REQ-005 | 0.72 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwDocumentService.php` | `getUserFolder()` | REQ-005 | 0.72 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwMappingService.php` | `__construct()` | REQ-001 | 0.85 | explicit: Explicit mapping-layer service |
| `lib/Service/ZgwMappingService.php` | `getMapping()` | REQ-001 | 0.85 | explicit: Explicit mapping-layer service |
| `lib/Service/ZgwMappingService.php` | `saveMapping()` | REQ-001 | 0.85 | explicit: Explicit mapping-layer service |
| `lib/Service/ZgwMappingService.php` | `listMappings()` | REQ-001 | 0.85 | explicit: Explicit mapping-layer service |
| `lib/Service/ZgwMappingService.php` | `deleteMapping()` | REQ-001 | 0.85 | explicit: Explicit mapping-layer service |
| `lib/Service/ZgwMappingService.php` | `getResourceKeys()` | REQ-001 | 0.85 | explicit: Explicit mapping-layer service |
| `lib/Service/ZgwMappingService.php` | `hasMapping()` | REQ-001 | 0.85 | explicit: Explicit mapping-layer service |
| `lib/Service/ZgwMappingService.php` | `resetToDefault()` | REQ-001 | 0.85 | explicit: Explicit mapping-layer service |
| `lib/Service/ZgwPaginationHelper.php` | `wrapResults()` | REQ-002 | 0.82 ⚠️ NEEDS-REVIEW | explicit: ZGW pagination helper — covers REQ-002 endpoint system |
| `lib/Service/ZgwService.php` | `__construct()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `getObjectService()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `getConsumerMapper()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `getZgwMappingService()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `getPaginationHelper()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `getDocumentService()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `getBusinessRulesService()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `getLogger()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `loadMappingConfig()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `translateQueryParams()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `createOutboundMapping()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `createInboundMapping()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `applyOutboundMapping()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `applyInboundMapping()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `getRequestBody()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `resolvePathUuid()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `updateCachedBodyField()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `buildBaseUrl()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `validateJwtAuth()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `consumerHasScope()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `getConsumerAuthorisaties()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `publishNotification()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `buildValidationError()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `unavailableResponse()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `mappingNotFoundResponse()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `handleIndex()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `handleCreate()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `handleShow()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `handleUpdate()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `handleDestroy()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `handleAudittrailIndex()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `handleAudittrailShow()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `resolveZaakClosed()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `resolveZaakClosedFromBody()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `resolveParentZaaktypeDraft()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `resolveParentZaaktypeDraftFromBody()` | REQ-002 | 0.85 | explicit: Central ZGW orchestrator — REQ-by-REQ mapping deferred to annotate |
| `lib/Service/ZgwService.php` | `mapAuditTrailToZgw()` | REQ-002 | 0.75 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |

### capability: zgw-business-rules-compliance — 44 methods across 3 files

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| `lib/Service/ZgwBusinessRulesService.php` | `__construct()` | ZRC-007 | 0.78 ⚠️ NEEDS-REVIEW | explicit: Business rules dispatcher — routes to *RulesService |
| `lib/Service/ZgwBusinessRulesService.php` | `validate()` | ZRC-007 | 0.78 ⚠️ NEEDS-REVIEW | explicit: Business rules dispatcher — routes to *RulesService |
| `lib/Service/ZgwBusinessRulesService.php` | `dispatchToRegister()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwBusinessRulesService.php` | `dispatchZrc()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwBusinessRulesService.php` | `dispatchZtc()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwBusinessRulesService.php` | `dispatchDrc()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwBusinessRulesService.php` | `dispatchBrc()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwBusinessRulesService.php` | `isValid()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwRulesBase.php` | `__construct()` | ZRC-007 | 0.75 ⚠️ NEEDS-REVIEW | explicit: Rules base class — shared helpers inherited by all rules services |
| `lib/Service/ZgwRulesBase.php` | `setContext()` | ZRC-007 | 0.75 ⚠️ NEEDS-REVIEW | explicit: Rules base class — shared helpers inherited by all rules services |
| `lib/Service/ZgwRulesBase.php` | `isValid()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwRulesBase.php` | `error()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwRulesBase.php` | `fieldError()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwRulesBase.php` | `fieldImmutableError()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwRulesBase.php` | `extractUuid()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwRulesBase.php` | `isValidUrl()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwRulesBase.php` | `validateTypeUrl()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwRulesBase.php` | `validateInformatieobjectUrl()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwRulesBase.php` | `validateExternalUrl()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwRulesBase.php` | `fetchExternalUrl()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwRulesBase.php` | `generateIdentificatie()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwRulesBase.php` | `findObjectByField()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwRulesBase.php` | `findAllObjectsByField()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwRulesBase.php` | `findBySchemaKey()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwRulesBase.php` | `checkFieldUniqueness()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwZrcRulesService.php` | `rulesZakenCreate()` | ZRC-007 | 0.78 ⚠️ NEEDS-REVIEW | explicit: ZRC rules service — specific ZRC-NNN REQ per method requires readin... |
| `lib/Service/ZgwZrcRulesService.php` | `rulesZakenUpdate()` | ZRC-007 | 0.78 ⚠️ NEEDS-REVIEW | explicit: ZRC rules service — specific ZRC-NNN REQ per method requires readin... |
| `lib/Service/ZgwZrcRulesService.php` | `rulesZakenPatch()` | ZRC-007 | 0.78 ⚠️ NEEDS-REVIEW | explicit: ZRC rules service — specific ZRC-NNN REQ per method requires readin... |
| `lib/Service/ZgwZrcRulesService.php` | `rulesStatussenCreate()` | ZRC-007 | 0.78 ⚠️ NEEDS-REVIEW | explicit: ZRC rules service — specific ZRC-NNN REQ per method requires readin... |
| `lib/Service/ZgwZrcRulesService.php` | `rulesResultatenCreate()` | ZRC-007 | 0.78 ⚠️ NEEDS-REVIEW | explicit: ZRC rules service — specific ZRC-NNN REQ per method requires readin... |
| `lib/Service/ZgwZrcRulesService.php` | `rulesRollenCreate()` | ZRC-007 | 0.78 ⚠️ NEEDS-REVIEW | explicit: ZRC rules service — specific ZRC-NNN REQ per method requires readin... |
| `lib/Service/ZgwZrcRulesService.php` | `rulesZaakinformatieobjectenCreate()` | ZRC-007 | 0.78 ⚠️ NEEDS-REVIEW | explicit: ZRC rules service — specific ZRC-NNN REQ per method requires readin... |
| `lib/Service/ZgwZrcRulesService.php` | `rulesZaakinformatieobjectenUpdate()` | ZRC-007 | 0.78 ⚠️ NEEDS-REVIEW | explicit: ZRC rules service — specific ZRC-NNN REQ per method requires readin... |
| `lib/Service/ZgwZrcRulesService.php` | `rulesZaakinformatieobjectenPatch()` | ZRC-007 | 0.78 ⚠️ NEEDS-REVIEW | explicit: ZRC rules service — specific ZRC-NNN REQ per method requires readin... |
| `lib/Service/ZgwZrcRulesService.php` | `rulesZaakeigenschappenCreate()` | ZRC-007 | 0.78 ⚠️ NEEDS-REVIEW | explicit: ZRC rules service — specific ZRC-NNN REQ per method requires readin... |
| `lib/Service/ZgwZrcRulesService.php` | `detectEindstatus()` | ZRC-007 | 0.78 ⚠️ NEEDS-REVIEW | explicit: ZRC rules service — specific ZRC-NNN REQ per method requires readin... |
| `lib/Service/ZgwZrcRulesService.php` | `filterZakenForConsumer()` | ZRC-007 | 0.78 ⚠️ NEEDS-REVIEW | explicit: ZRC rules service — specific ZRC-NNN REQ per method requires readin... |
| `lib/Service/ZgwZrcRulesService.php` | `deriveVertrouwelijkheidaanduiding()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwZrcRulesService.php` | `validateSubResourceType()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwZrcRulesService.php` | `validateZioInformatieobjecttype()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwZrcRulesService.php` | `validateZaakFields()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwZrcRulesService.php` | `validateHoofdzaakNesting()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwZrcRulesService.php` | `validateProductenOfDiensten()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |
| `lib/Service/ZgwZrcRulesService.php` | `checkZioImmutability()` | ZRC-007 | 0.7 ⚠️ NEEDS-REVIEW | Pass B — inherited from public sibling in same class |

## Bucket 2a — Existing capability, no REQ (reverse-spec --extend)

### cluster: zgw-business-rules-compliance (39 methods) → `/opsx-reverse-spec procest --extend zgw-business-rules-compliance`

- `lib/Service/ZgwBrcRulesService.php` — 10 methods: `rulesBesluitenCreate()`, `rulesBesluitenUpdate()`, `rulesBesluitenPatch()`, `rulesBesluitinformatieobjectenCreate()`, `checkBesluitTypeImmutability()`, `checkBesluitFieldImmutability()`, `preserveImmutableBesluitFields()`, `checkBesluitIdentificatieUnique()` + 2 more
- `lib/Service/ZgwDrcRulesService.php` — 13 methods: `rulesEnkelvoudiginformatieobjectenCreate()`, `rulesEnkelvoudiginformatieobjectenUpdate()`, `rulesEnkelvoudiginformatieobjectenPatch()`, `rulesEnkelvoudiginformatieobjectenDestroy()`, `rulesObjectinformatieobjectenCreate()`, `findOioRelationsForDocument()`, `validateIndicatieGebruiksrechtTrue()`, `validateObjectUrl()` + 5 more
- `lib/Service/ZgwZtcRulesService.php` — 16 methods: `checkConceptProtection()`, `defaultConcept()`, `preserveConcept()`, `rulesZaaktypenCreate()`, `rulesBesluittypenCreate()`, `rulesZaaktypeinformatieobjecttypenCreate()`, `rulesResultaattypenCreate()`, `checkDirectConceptProtection()` + 8 more

### cluster: zgw-api-mapping (13 methods) → `/opsx-reverse-spec procest --extend zgw-api-mapping`

- `lib/Middleware/ZgwAuthException.php::getStatusCode()` — Exception type for ZgwAuthMiddleware
- `lib/Middleware/ZgwAuthMiddleware.php` — 8 methods: `beforeController()`, `afterException()`, `isConfidentialityAllowed()`, `loadOpenRegisterServices()`, `enforceScopes()`, `scopeGrantCovers()`, `decodeJwtPayload()`, `findConsumerByIssuer()`
- `lib/Service/NotificatieService.php::publish()` — NotificatieService::publish()
- `lib/Service/NotificatieService.php::loadOpenRegisterServices()` — NotificatieService::loadOpenRegisterServices()
- `lib/Service/NotificatieService.php::deliver()` — NotificatieService::deliver()
- `lib/Service/NotificatieService.php::deliverToSubscription()` — NotificatieService::deliverToSubscription()

### cluster: admin-settings (12 methods) → `/opsx-reverse-spec procest --extend admin-settings`

- `lib/Controller/SettingsController.php::getObjectService()` — SettingsController::getObjectService()
- `lib/Controller/SettingsController.php::getConfigurationService()` — SettingsController::getConfigurationService()
- `lib/Controller/SettingsController.php::index()` — SettingsController::index()
- `lib/Controller/SettingsController.php::create()` — SettingsController::create()
- `lib/Controller/SettingsController.php::load()` — SettingsController::load()
- `lib/Service/SettingsService.php` — 7 methods: `isOpenRegisterAvailable()`, `loadConfiguration()`, `getSettings()`, `updateSettings()`, `getConfigValue()`, `setConfigValue()`, `autoConfigureAfterImport()`

### cluster: workflow-import-export (11 methods) → `/opsx-reverse-spec procest --extend workflow-import-export`

- `lib/Controller/CaseDefinitionController.php::export()` — CaseDefinitionController::export()
- `lib/Controller/CaseDefinitionController.php::validate()` — CaseDefinitionController::validate()
- `lib/Controller/CaseDefinitionController.php::import()` — CaseDefinitionController::import()
- `lib/Service/CaseDefinitionExportService.php::exportCaseDefinition()` — CaseDefinitionExportService::exportCaseDefinition()
- `lib/Service/CaseDefinitionExportService.php::buildManifest()` — CaseDefinitionExportService::buildManifest()
- `lib/Service/CaseDefinitionExportService.php::exportComponent()` — CaseDefinitionExportService::exportComponent()
- `lib/Service/CaseDefinitionExportService.php::incrementVersion()` — CaseDefinitionExportService::incrementVersion()
- `lib/Service/CaseDefinitionImportService.php::validatePackage()` — CaseDefinitionImportService::validatePackage()
- `lib/Service/CaseDefinitionImportService.php::importCaseDefinition()` — CaseDefinitionImportService::importCaseDefinition()
- `lib/Service/CaseDefinitionImportService.php::importComponent()` — CaseDefinitionImportService::importComponent()
- `lib/Service/CaseDefinitionImportService.php::importWorkflows()` — CaseDefinitionImportService::importWorkflows()

### cluster: advice-management (10 methods) → `/opsx-reverse-spec procest --extend advice-management`

- `lib/Controller/ConsultationController.php::index()` — ConsultationController::index()
- `lib/Controller/ConsultationController.php::create()` — ConsultationController::create()
- `lib/Controller/ConsultationController.php::updateStatus()` — ConsultationController::updateStatus()
- `lib/Controller/ConsultationController.php::submitResponse()` — ConsultationController::submitResponse()
- `lib/Controller/ConsultationController.php::overdue()` — ConsultationController::overdue()
- `lib/Service/ConsultationService.php::createConsultation()` — ConsultationService::createConsultation()
- `lib/Service/ConsultationService.php::getConsultationsForCase()` — ConsultationService::getConsultationsForCase()
- `lib/Service/ConsultationService.php::updateStatus()` — ConsultationService::updateStatus()
- `lib/Service/ConsultationService.php::submitResponse()` — ConsultationService::submitResponse()
- `lib/Service/ConsultationService.php::getOverdueConsultations()` — ConsultationService::getOverdueConsultations()

### cluster: case-management (9 methods) → `/opsx-reverse-spec procest --extend case-management`

- `lib/Controller/EmailController.php::send()` — EmailController::send()
- `lib/Controller/EmailController.php::sendFromTemplate()` — EmailController::sendFromTemplate()
- `lib/Controller/EmailController.php::preview()` — EmailController::preview()
- `lib/Controller/EmailController.php::templates()` — EmailController::templates()
- `lib/Service/CaseEmailService.php::sendEmail()` — CaseEmailService::sendEmail()
- `lib/Service/CaseEmailService.php::sendFromTemplate()` — CaseEmailService::sendFromTemplate()
- `lib/Service/CaseEmailService.php::resolveVariables()` — CaseEmailService::resolveVariables()
- `lib/Service/CaseEmailService.php::findUnresolvedVariables()` — CaseEmailService::findUnresolvedVariables()
- `lib/Service/CaseEmailService.php::processInbound()` — CaseEmailService::processInbound()

### cluster: wms-wfs-layers (2 methods) → `/opsx-reverse-spec procest --extend wms-wfs-layers`

- `lib/Controller/GisProxyController.php::capabilities()` — GisProxyController::capabilities()
- `lib/Service/GisProxyService.php::getCapabilities()` — GisProxyService::getCapabilities()

## Bucket 2b — No capability owner (reverse-spec --cluster)

### cluster: ai-assistant (43 methods) → `/opsx-reverse-spec procest --cluster ai-assistant`

- `lib/Controller/AiController.php` — 12 methods: `classify()`, `extract()`, `ask()`, `summarize()`, `suggestRouting()`, `suggestNext()`, `recordAction()`, `auditIndex()` + 4 more
- `lib/Service/AiService.php` — 21 methods: `isEnabled()`, `isFeatureEnabled()`, `classifyDocument()`, `extractData()`, `askQuestion()`, `summarize()`, `suggestRouting()`, `suggestNextStep()` + 13 more
- `src/services/aiApi.js` — 10 methods: `classifyDocument()`, `extractData()`, `askQuestion()`, `summarize()`, `suggestRouting()`, `suggestNext()`, `getAuditLog()`, `getAiSettings()` + 2 more

### cluster: appointments (35 methods) → `/opsx-reverse-spec procest --cluster appointments`

- `lib/BackgroundJob/AppointmentReminderJob.php::run()`
- `lib/Controller/AppointmentController.php::index()`
- `lib/Controller/AppointmentController.php::create()`
- `lib/Controller/AppointmentController.php::cancel()`
- `lib/Controller/AppointmentController.php::noShow()`
- `lib/Controller/AppointmentController.php::timeslots()`
- `lib/Controller/PublicAppointmentController.php::view()`
- `lib/Controller/PublicAppointmentController.php::cancel()`
- `lib/Service/AppointmentBackend/JccBackend.php::getTimeslots()`
- `lib/Service/AppointmentBackend/JccBackend.php::bookAppointment()`
- `lib/Service/AppointmentBackend/JccBackend.php::cancelAppointment()`
- `lib/Service/AppointmentBackend/JccBackend.php::rescheduleAppointment()`
- `lib/Service/AppointmentBackend/LocalBackend.php::getTimeslots()`
- `lib/Service/AppointmentBackend/LocalBackend.php::bookAppointment()`
- `lib/Service/AppointmentBackend/LocalBackend.php::cancelAppointment()`
- `lib/Service/AppointmentBackend/LocalBackend.php::rescheduleAppointment()`
- `lib/Service/AppointmentBackend/QmaticBackend.php::getTimeslots()`
- `lib/Service/AppointmentBackend/QmaticBackend.php::bookAppointment()`
- `lib/Service/AppointmentBackend/QmaticBackend.php::cancelAppointment()`
- `lib/Service/AppointmentBackend/QmaticBackend.php::rescheduleAppointment()`
- `lib/Service/AppointmentService.php` — 8 methods: `getTimeslots()`, `bookAppointment()`, `cancelAppointment()`, `markNoShow()`, `getAppointmentsForCase()`, `getAppointmentByToken()`, `getBackend()`, `getObjectService()`
- `src/services/appointmentApi.js::listAppointments()`
- `src/services/appointmentApi.js::bookAppointment()`
- `src/services/appointmentApi.js::cancelAppointment()`
- `src/services/appointmentApi.js::markNoShow()`
- `src/services/appointmentApi.js::getTimeslots()`
- `src/views/public/PublicAppointmentPage.vue::formatDateTime()`
- `src/views/public/PublicAppointmentPage.vue::if()`

### cluster: case-sharing (29 methods) → `/opsx-reverse-spec procest --cluster case-sharing`

- `lib/BackgroundJob/ShareMaintenanceJob.php::run()`
- `lib/Controller/CaseSharingController.php::listShares()`
- `lib/Controller/CaseSharingController.php::createShare()`
- `lib/Controller/CaseSharingController.php::revokeShare()`
- `lib/Controller/CaseSharingController.php::initiateTransfer()`
- `lib/Controller/CaseSharingController.php::handleTransfer()`
- `lib/Controller/PublicShareController.php::accessShare()`
- `lib/Controller/PublicShareController.php::addComment()`
- `lib/Controller/PublicShareController.php::viewStatus()`
- `lib/Controller/PublicShareController.php::loadCaseData()`
- `lib/Service/CaseSharingService.php` — 11 methods: `generateToken()`, `createTokenShare()`, `createPartnerShare()`, `getSharesByCase()`, `revokeShare()`, `validateToken()`, `getFilteredCaseData()`, `maskBsn()` + 3 more
- `lib/Service/CaseTransferService.php::initiateTransfer()`
- `lib/Service/CaseTransferService.php::acceptTransfer()`
- `lib/Service/CaseTransferService.php::rejectTransfer()`
- `lib/Service/CaseTransferService.php::getObjectService()`
- `src/views/public/PublicCaseView.vue::loadShareData()`
- `src/views/public/PublicCaseView.vue::if()`
- `src/views/public/PublicStatusPage.vue::loadStatus()`
- `src/views/public/PublicStatusPage.vue::if()`

### cluster: stuf-protocol (29 methods) → `/opsx-reverse-spec procest --cluster stuf-protocol`

- `lib/Controller/StufController.php` — 10 methods: `zaken()`, `personen()`, `handleSoapMessage()`, `handleZakLk01()`, `handleZakLv01()`, `handleNpsLv01()`, `handleEdcLk01()`, `handleUnknownMessage()` + 2 more
- `lib/Service/StufFieldMappingService.php` — 13 methods: `mapZknToInternal()`, `mapInternalToZkn()`, `mapBgToInternal()`, `mapInternalToBg()`, `stufDateToIso()`, `isoToStufDate()`, `isoToStufDateTime()`, `confidentialityToInternal()` + 5 more
- `lib/Service/StufMessageBuilder.php::buildSoapEnvelope()`
- `lib/Service/StufMessageBuilder.php::buildStuurgegevens()`
- `lib/Service/StufMessageBuilder.php::buildBv01()`
- `lib/Service/StufMessageBuilder.php::buildFo01()`
- `lib/Service/StufMessageBuilder.php::buildSoapFault()`
- `lib/Service/StufMessageBuilder.php::generateUuid()`

### cluster: multi-tenancy (24 methods) → `/opsx-reverse-spec procest --cluster multi-tenancy`

- `lib/Controller/TenantController.php::index()`
- `lib/Controller/TenantController.php::create()`
- `lib/Controller/TenantController.php::provision()`
- `lib/Controller/TenantController.php::usage()`
- `lib/Controller/TenantController.php::current()`
- `lib/Controller/TenantController.php::isPlatformAdmin()`
- `lib/Middleware/TenantMiddleware.php::beforeController()`
- `lib/Middleware/TenantMiddleware.php::afterException()`
- `lib/Service/TenantService.php` — 9 methods: `getTenantForUser()`, `getTenantByGroupId()`, `createTenant()`, `provisionTenant()`, `getResourceUsage()`, `isUserInTenant()`, `isPlatformAdmin()`, `slugify()` + 1 more
- `src/services/tenantApi.js` — 7 methods: `listTenants()`, `createTenant()`, `getTenant()`, `updateTenant()`, `provisionTenant()`, `getTenantUsage()`, `getCurrentTenant()`

### cluster: leges (21 methods) → `/opsx-reverse-spec procest --cluster leges`

- `lib/Controller/LegesController.php::calculate()`
- `lib/Controller/LegesController.php::recalculate()`
- `lib/Controller/LegesController.php::verrekening()`
- `lib/Controller/LegesController.php::teruggaaf()`
- `lib/Controller/LegesController.php::export()`
- `lib/Service/LegesCalculationService.php` — 10 methods: `calculate()`, `recalculate()`, `calculateVerrekening()`, `calculateTeruggaaf()`, `calculateArtikel()`, `calculateVast()`, `calculatePercentage()`, `calculateStaffel()` + 2 more
- `lib/Service/LegesExportService.php::export()`
- `lib/Service/LegesExportService.php::exportCSV()`
- `lib/Service/LegesExportService.php::exportASCII()`
- `lib/Service/LegesExportService.php::exportXML()`
- `lib/Service/LegesExportService.php::flattenBerekening()`
- `lib/Service/LegesExportService.php::addXmlElement()`

### cluster: berichtenbox (17 methods) → `/opsx-reverse-spec procest --cluster berichtenbox`

- `lib/BackgroundJob/BerichtenboxReadStatusJob.php::run()`
- `lib/Controller/BerichtenboxController.php::send()`
- `lib/Controller/BerichtenboxController.php::messages()`
- `lib/Controller/BerichtenboxController.php::poll()`
- `lib/Service/BerichtenboxAdapter/MockAdapter.php::sendMessage()`
- `lib/Service/BerichtenboxAdapter/MockAdapter.php::getReadStatus()`
- `lib/Service/BerichtenboxService.php` — 7 methods: `sendMessage()`, `getMessagesForCase()`, `pollReadStatus()`, `validateBsn()`, `validateMessage()`, `getAdapter()`, `getObjectService()`
- `src/services/berichtenboxApi.js::sendMessage()`
- `src/services/berichtenboxApi.js::listMessages()`
- `src/services/berichtenboxApi.js::getTypeCodes()`
- `src/services/berichtenboxApi.js::pollReadStatus()`

### cluster: templates (6 methods) → `/opsx-reverse-spec procest --cluster templates`

- `lib/Controller/TemplateController.php::index()`
- `lib/Controller/TemplateController.php::show()`
- `lib/Controller/TemplateController.php::activate()`
- `lib/Service/TemplateLibraryService.php::listTemplates()`
- `lib/Service/TemplateLibraryService.php::loadTemplate()`
- `lib/Service/TemplateLibraryService.php::activateTemplate()`

### cluster: dso-intake (2 methods) → `/opsx-reverse-spec procest --cluster dso-intake`

- `lib/Service/DsoIntakeService.php::processAanvraag()`
- `lib/Service/DsoIntakeService.php::getDeadlineDuration()`

## Bucket 3 — Surfaced for human triage

### 3a — possibly broken

*Skipped this run — `git log -S` heuristic was noisy on a greenfield-over-specs codebase. Every unmatched REQ collapses to 3b with a note.*

### 3b — never implemented (73 REQs in 19 untouched capabilities)

*These are REQs in capabilities where **zero** methods landed in Bucket 1. They're likely unimplemented — not just unmatched.*

#### advice-management (3 REQs)

- `REQ-001` — Advice request schema
- `REQ-002` — Advice panel on case dashboard
- `REQ-003` — Advice request form

#### automatic-actions (2 REQs)

- `REQ-001` — Automatic Action Framework
- `REQ-002` — Action Execution Error Handling

#### beroep-escalation (5 REQs)

- `REQ-001` — Beroep Case Type Pre-Seeded Configuration
- `REQ-002` — Beroep Status Types
- `REQ-003` — Escalation from Bezwaar to Beroep
- `REQ-004` — Court Proceedings Document Management
- `REQ-005` — Hoger Beroep Awareness

#### bezwaar-advisory-committee (2 REQs)

- `REQ-001` — Advisory Committee Report Schema
- `REQ-002` — Committee Composition Tracking

#### bezwaar-decision (3 REQs)

- `REQ-001` — Decision on Objection Schema
- `REQ-002` — Decision Notification
- `REQ-003` — Heroverweging (Full Reconsideration)

#### bezwaar-hearing (3 REQs)

- `REQ-001` — Hearing Session Management
- `REQ-002` — Hearing Waiver (Afzien van Hoorrecht)
- `REQ-003` — Hearing Participants and Access Rights

#### case-location (4 REQs)

- `REQ-LOC-01` — Case Detail Map Tab
- `REQ-LOC-02` — Location Picker
- `REQ-LOC-03` — Address Display and Reverse Geocoding
- `REQ-LOC-04` — Case Creation Location

#### deelzaak-support (6 REQs)

- `REQ-001` — Sub-case creation from parent case
- `REQ-002` — Sub-cases section on parent case detail
- `REQ-003` — Parent case breadcrumb navigation
- `REQ-004` — Sub-case progress roll-up on parent case
- `REQ-005` — Sub-case count in case list
- `REQ-006` — Sub-case deletion protection

#### enforcement-lhs (4 REQs)

- `REQ-001` — LHS matrix configuration
- `REQ-002` — Enforcement action schema
- `REQ-003` — Enforcement wizard
- `REQ-004` — Enforcement panel on case dashboard

#### parafering-dashboard (4 REQs)

- `REQ-001` — Secretariaat Parafering Overview
- `REQ-002` — Personal Parafering Inbox
- `REQ-003` — Send Parafering Reminder
- `REQ-004` — Voorstel List Navigation

#### process-step-configuration (2 REQs)

- `REQ-001` — Process Step CRUD within Workflow
- `REQ-002` — Step-to-Task Mapping at Runtime

#### procest-case-management (13 REQs)

- `REQ-001` — 1: Register and schemas MUST be auto-configured on install
- `REQ-002` — 2: Cases list view MUST display paginated, searchable case overview
- `REQ-003` — 3: Case create dialog MUST support type-driven case creation
- `REQ-004` — 4: Case detail view MUST display full case information with related data
- `REQ-005` — 5: Status lifecycle MUST support configurable status flows with mandatory result on closure
- `REQ-006` — 6: Deadline and timing MUST support processing deadlines with extensions
- `REQ-007` — 7: Tasks MUST be manageable within case context
- `REQ-008` — 8: Participants MUST be manageable per case
- `REQ-009` — 9: Activity timeline MUST record all case events
- `REQ-010` — 10: Case type administration MUST support configuring case types
- `REQ-011` — 11: Navigation MUST include all primary views
- `REQ-012` — 12: Dashboard MUST provide overview metrics and quick access
- `REQ-013` — 13: ZGW API compatibility MUST be maintained

#### role-based-step-routing (3 REQs)

- `REQ-001` — Role-Based Step Visibility
- `REQ-002` — Role-Based Transition Access
- `REQ-003` — Workflow Inheritance for Role Configuration

#### status-transition-engine (3 REQs)

- `REQ-001` — Guard Evaluation Engine
- `REQ-002` — Transition Execution
- `REQ-003` — Available Transitions for Current User

#### visual-workflow-editor (3 REQs)

- `REQ-001` — Drag-and-Drop Workflow Canvas
- `REQ-002` — Workflow Editor Validation
- `REQ-003` — Step Configuration Panel

#### vth-workflow-templates (4 REQs)

- `REQ-001` — Omgevingsvergunning workflow template
- `REQ-002` — Toezichtzaak workflow template
- `REQ-003` — Handhavingszaak workflow template
- `REQ-004` — VTH workflow template library

#### workflow-definition-model (5 REQs)

- `REQ-001` — Workflow Template Data Model
- `REQ-002` — Workflow Step Data Model
- `REQ-003` — Status Transition Data Model
- `REQ-004` — Pre-Seeded Bezwaar Workflow Template
- `REQ-005` — Pre-Seeded Beroep Workflow Template

#### workflow-import-export (2 REQs)

- `REQ-001` — Export Workflow Template
- `REQ-002` — Import Workflow Template

#### zaaktype-versioning (2 REQs)

- `REQ-001` — Workflow Template Versioning
- `REQ-002` — Case-to-Workflow-Version Binding

## Bucket 4 — ADR conformance findings

### missing `@license` in file docblock (13 files)

- `lib/BackgroundJob/AppointmentReminderJob.php`
- `lib/BackgroundJob/BerichtenboxReadStatusJob.php`
- `lib/Controller/AppointmentController.php`
- `lib/Controller/BerichtenboxController.php`
- `lib/Controller/PublicAppointmentController.php`
- `lib/Service/AppointmentBackend/AppointmentBackendInterface.php`
- `lib/Service/AppointmentBackend/JccBackend.php`
- `lib/Service/AppointmentBackend/LocalBackend.php`
- `lib/Service/AppointmentBackend/QmaticBackend.php`
- `lib/Service/AppointmentService.php`
- `lib/Service/BerichtenboxAdapter/BerichtenboxAdapterInterface.php`
- `lib/Service/BerichtenboxAdapter/MockAdapter.php`
- `lib/Service/BerichtenboxService.php`

### missing `@copyright` in file docblock (13 files)

Same 13 files — both `@license` and `@copyright` missing together (appointments + berichtenbox clusters).

### missing `@spec` in file docblock (89 of 89 files)

Expected — this is exactly what retrofit fixes. Not a separate finding.

### forbidden debug helpers — 0 hits ✓

### direct SQL — 0 files ✓ (ADR-001 compliant)

## Notes for the human reviewer

- case-management/spec.md has duplicated REQ blocks (pilot noted lines 63-945 vs 1013-1946). Pre-retrofit cleanup recommended; counted 45 REQ headings but ~22 are unique.
- Frontend (Vue/TS) classification is file-level — every method in a mapped view inherits the capability's first REQ with confidence 0.78 NEEDS-REVIEW. Specific REQ per method requires reading the component body during /opsx-annotate.
- Large ZGW controllers and rules services (ZrcController 39 methods, ZgwService 37, ZgwZrcRulesService 19) are bucketed via explicit file-level overrides to zgw-api-mapping / zgw-business-rules-compliance. Per-method REQ assignment deferred to annotate.
- Bucket 2b cluster 'workflow-import-export' is tentative — a workflow-import-export spec exists (2 REQs) but my classifier kept CaseDefinition* files in 2a until human confirms scope overlap.
- zgw-business-rules-compliance spec only covers ZRC rules (11 REQs). Methods in ZgwDrcRulesService, ZgwZtcRulesService, ZgwBrcRulesService landed in Bucket 2a — they extend the capability but have no matching REQ in the spec.
- Bucket 3b is computed conservatively: only REQs in capabilities that have ZERO Bucket 1 methods are marked unimplemented. REQs in 'touched' capabilities (admin-settings, case-management, etc.) are assumed implemented but not precisely mapped — their specific REQ-to-method assignment is deferred to /opsx-annotate.
- 3a classification disabled — git log -S heuristic was unreliable on this greenfield-over-specs codebase; every untouched REQ collapsed to 3b with a note.
