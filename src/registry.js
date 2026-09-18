// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// V2 component registry for dossiq.
//
// Every entry here corresponds to a manifest `type: "custom"` page or a
// sidebar tab that uses `component:` instead of `widgets[]`. The registry
// maps the string key used in the manifest to a `{ kind, component }` entry
// so CnAppRoot can resolve the component at render time.
//
// Recognised kinds: page, modal, widget, form-field, cell-renderer
//
// Migration notes:
// - The visual workflow editor (`WorkflowEditor.vue`) is not a registry entry
//   — it is a plain child component mounted by `WorkflowTab.vue` inside the
//   case-type detail page's "Workflow" tab, not a manifest `type:"custom"`
//   page or sidebar-tab component. See openspec/specs/visual-workflow-editor.
//   A second, @vue-flow-based implementation (Vue-3-only, incompatible with
//   this app's Vue 2.7 build) was removed by workflow-editor-integration.
// - `MapComponent` is kept in customComponents.js for backward compat with any
//   manifest entries that reference it by string outside the registry. No
//   current manifest pages reference MapComponent by key directly; retained as
//   a pass-through.

import BesluitPublicatiePanel from './components/besluitvorming/BesluitPublicatiePanel.vue'
// The case's archival future as openregister decided it, on the Archiving tab.
// @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
import CaseArchivalPanel from './components/case/CaseArchivalPanel.vue'
// The line saying this case is in the archive, and what that means for the
// reader (archived-cases-leave-the-lenses).
// @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
import CaseArchivedStrip from './components/case/CaseArchivedStrip.vue'
// The flag a person raised, the risk the organisation assessed, and the
// markers the system raised against a named panel.
// @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
import CaseAttentionPanel from './components/case/CaseAttentionPanel.vue'
// The star on the case page (case-number-and-favourites, row 2.19).
// @spec openspec/changes/case-number-and-favourites/specs/case-management/spec.md
// Who has held this case, and who is asking for it
// (custody-and-handover-of-a-case, rows 2.37 and 2.38).
// @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
import CaseCustodyPanel from './components/case/CaseCustodyPanel.vue'
import CaseFavouriteStrip from './components/case/CaseFavouriteStrip.vue'
// Follow a case you do not own, and see who else does (case-followers,
// row 13.18), over OpenRegister's own subscription (`object-watchers`).
// @spec openspec/changes/case-followers/specs/case-management/spec.md
import CaseFollowersPanel from './components/case/CaseFollowersPanel.vue'
import CaseFollowStrip from './components/case/CaseFollowStrip.vue'
// The inline task pane on the case page (task-on-the-case A06).
// @spec openspec/specs/task-management/spec.md
// The case's own locations on a map, on the Data tab.
// @spec openspec/specs/case-dashboard-view/spec.md
import CaseLocationMap from './components/case/CaseLocationMap.vue'
// Who is on the case and in which role, over OpenRegister's party model.
// @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
import CasePartiesWidget from './components/case/CasePartiesWidget.vue'
// The case's own state on the case page is no longer a registry component at
// all: the identity band is four configured library tiles (stat + countdown)
// and the stepper is the library `stages` widget, which is also how the case
// is moved. CaseHeaderRow, CaseStepsWidget and CaseTransitionsWidget are gone.
// @spec openspec/specs/status-transition-engine/spec.md
// @spec openspec/specs/case-dashboard-view/spec.md
import CasePlannedWidget from './components/case/CasePlannedWidget.vue'
// The adaptive case plan, served by OpenRegister's case layer rather than by
// dossiq's own CMMN runtime (retire-cmmn-caseplanstate, group 1).
// @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md
import CasePlanPanel from './components/case/CasePlanPanel.vue'
import CasePlanSociaalDomeinPanel from './components/case/CasePlanSociaalDomeinPanel.vue'
// What is new on this case since the handler last looked, and where.
// @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
import CaseStatusDeclarationPanel from './components/case/CaseStatusDeclarationPanel.vue'
// What the status this case is in declares: what is still missing before a
// derived status fires, who the case waits on, and how long it has been here.
// @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
import CaseUnreadPanel from './components/case/CaseUnreadPanel.vue'
// A reviewer's own pending archival decisions, on My Work.
// @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
import MyArchivalReviews from './components/case/MyArchivalReviews.vue'
import RoleTypePicker from './components/case/RoleTypePicker.vue'
// The case type's effective blueprint: what it offers, and what it inherited.
// @spec openspec/specs/case-types/spec.md
import CaseTypeBlueprintWidget from './components/caseType/CaseTypeBlueprintWidget.vue'
// Case-list CSV/Excel export via the OR export leaf — actions-slot component
// on the Cases page (manifest `pages[].actionsComponent`). Builds the OR
// export-leaf URL client-side; no dossiq-side serialization (ADR-022).
// @spec openspec/specs/case-list-export-via-or-export-leaf/spec.md
import CaseListExportAction from './components/export/CaseListExportAction.vue'
// Initiator (indiener) selection + display — brp-kvk-register-sets.
// @spec openspec/specs/initiator-selection/spec.md
import InitiatorPicker from './components/initiator/InitiatorPicker.vue'
import RequesterProjection from './components/initiator/RequesterProjection.vue'
// A search openregister refused, said where the term was typed.
// @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
import CaseSearchRefusal from './components/search/CaseSearchRefusal.vue'
// "Besluitvorming" decision-making is owned by decidesk and surfaced here as
// an OR integration leaf (decidesk-decisions) on the case-detail sidebar.
// @spec openspec/changes/consume-decidesk-besluitvorming-leaf/tasks.md
import BesluitvormingLeafTab from './components/tabs/BesluitvormingLeafTab.vue'
import CaseDocumentsTab from './components/tabs/CaseDocumentsTab.vue'
// Detail-tab components (used as `component:` in sidebarTabs[])
import CaseTasksTab from './components/tabs/CaseTasksTab.vue'
import CaseTaskPane from './components/tasks/CaseTaskPane.vue'
// Generate document — the CaseDetail header action's template picker.
// @spec openspec/specs/beschikking-generatie/spec.md
import BeschikkingComposerDialog from './dialogs/BeschikkingComposerDialog.vue'
// The Actions menu's non-lifecycle gestures: copy this case, start a flow
// its type allows, and plan a follow-up case for a later date.
// @spec openspec/specs/case-management/spec.md
import CaseCopyDialog from './dialogs/CaseCopyDialog.vue'
import CaseHandoverDialog from './dialogs/CaseHandoverDialog.vue'
import CaseLifecycleActionDialog from './dialogs/CaseLifecycleActionDialog.vue'
import CaseLifecycleMenuDialog from './dialogs/CaseLifecycleMenuDialog.vue'
import CaseMergeDialog from './dialogs/CaseMergeDialog.vue'
import CasePlanFollowUpDialog from './dialogs/CasePlanFollowUpDialog.vue'
// The case as OpenRegister stored it, behind the admin-only Inspect action.
// @spec openspec/changes/admin-inspect-entry/specs/case-management/spec.md
import CaseRawDataDialog from './dialogs/CaseRawDataDialog.vue'
import CaseRebindDialog from './dialogs/CaseRebindDialog.vue'
import CaseStartFlowDialog from './dialogs/CaseStartFlowDialog.vue'
// The three case-type gestures a declarative action cannot carry: a file, a
// change note, and a route to the copy (case-type-authoring-extras D5).
// @spec openspec/specs/workflow-import-export/spec.md
// @spec openspec/specs/zaaktype-versioning/spec.md
import CaseTypeDuplicateDialog from './dialogs/CaseTypeDuplicateDialog.vue'
import CaseTypeImportDialog from './dialogs/CaseTypeImportDialog.vue'
// The version chain: starting the next version, and moving one running case
// along it (case-type-version-chain).
// @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
import CaseTypeNewVersionDialog from './dialogs/CaseTypeNewVersionDialog.vue'
import CaseTypePublishDialog from './dialogs/CaseTypePublishDialog.vue'
import CaseVersionMoveDialog from './dialogs/CaseVersionMoveDialog.vue'
import CrossDomainLookupDialog from './dialogs/CrossDomainLookupDialog.vue'
// Remind a colleague about this case on a date (case-reminder-as-task).
// @spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md
import RemindDialog from './dialogs/RemindDialog.vue'
import BulkDocumentActionDialog from './modals/BulkDocumentActionDialog.vue'
// The Documents tab's upload dialog and bulk-action dialog
// (documents-on-the-case task 2.2: the tab itself is now a `type:
// "object-list"` CnObjectListWidget, resolved by the library, not a
// registry widget entry — only the two modals it dispatches to are ours).
// @spec openspec/specs/document-zaakdossier/spec.md
// @spec openspec/specs/document-zaakdossier/spec.md
import DocumentMetadataDialog from './modals/DocumentMetadataDialog.vue'
import FileRequestDialog from './modals/FileRequestDialog.vue'
import VersionHistoryPanel from './modals/VersionHistoryPanel.vue'
import SubstitutionAdminView from './views/admin/SubstitutionAdmin.vue'
// VTH-specific case detail panels
import AdviceRequestPanel from './views/cases/components/AdviceRequestPanel.vue'
import AdviesPanel from './views/cases/components/AdviesPanel.vue'
// Federated case sharing/transfer/activity — federated-case-collaboration.
// @spec openspec/specs/federated-case-collaboration/spec.md
import CaseAccessTab from './views/cases/components/CaseAccessTab.vue'
// Case-assistant chat panel — conversational assistance delegated to Hermiq
// (fleet rule: AI functionality lives in Hermiq; dossiq is a thin consumer).
// @spec openspec/specs/case-assistant-via-hermiq/spec.md
// Case-email integration — leaf-first per ADR-022. The sidebar tab
// wraps the EmailThread component (display only), reuses NC Mail as
// the email engine, and triggers prefillDraft via the case-email API.
// @spec openspec/changes/case-email-integration/tasks.md#T12
import CaseConversationsPanel from './views/cases/components/CaseConversationsPanel.vue'
import CaseEmailTab from './views/cases/components/CaseEmailTab.vue'
import CaseNotesTab from './views/cases/components/CaseNotesTab.vue'
import CaseSharingTab from './views/cases/components/CaseSharingTab.vue'
import CaseTermsTab from './views/cases/components/CaseTermsTab.vue'
import CaseTimelineTab from './views/cases/components/CaseTimelineTab.vue'
// The AVG panel on a data subject request case
// (data-subject-requests-drive-the-platform).
import DataSubjectRequestTab from './views/cases/components/DataSubjectRequestTab.vue'
// CMMN adaptive case-plan panel — sibling to the BPMN status-transition
// engine, for caseTypes with handlingModel = 'cmmn' (cmmn-adaptive-case).
// @spec openspec/specs/cmmn-adaptive-case/spec.md
import InspectionChecklistPanel from './views/cases/components/InspectionChecklistPanel.vue'
import InspectionPanel from './views/cases/components/InspectionPanel.vue'
import DeelzaakDetail from './views/cases/DeelzaakDetail.vue'
// Deelzaak (sub-case) full-page views — wired via manifest routes
// /cases/:id/deelzaken (list) and /cases/:parentId/deelzaken/:id (detail).
// Modal isolation per ADR-004: DeelzaakCreateModal lives in src/modals/.
// @spec openspec/changes/deelzaak-support/tasks.md#T05
// @spec openspec/changes/deelzaak-support/tasks.md#T06
import DeelzaakList from './views/cases/DeelzaakList.vue'
// Cases-on-map — full-screen multi-object overview. Consumes OpenRegister's
// page-level maps-overview leaf (OR #154): OR owns the geometry extraction,
// RBAC scoping, and base-layer config; the markers render through the lib's
// `CnMapWidget`. No bespoke Leaflet / WMS / WFS stack in dossiq (ADR-022).
// @spec openspec/specs/case-map-overview/spec.md
import CasesOnMapView from './views/CasesOnMapView.vue'
import FlowDetailSidebar from './views/flows/FlowDetailSidebar.vue'
import MyWorkView from './views/MyWorkCards.vue'
import PublicAppointmentPage from './views/public/PublicAppointmentPage.vue'
import PublicFederatedTransferPage from './views/public/PublicFederatedTransferPage.vue'
import PublicStatusPage from './views/public/PublicStatusPage.vue'
import EndOfDayView from './views/queue/EndOfDayView.vue'
// One personal queue fed by the declared sources (one-personal-queue).
// @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
import PersonalQueueView from './views/queue/PersonalQueueView.vue'
// The task page (`/tasks/:id`) over OpenRegister's task engine. Replaced the
// `type: "detail"` page when remove-casetask took the caseTask schema away:
// CnDetailPage has no entity-source mode, so a detail page can only bind a
// register and a schema. Route and page id unchanged, so deep links resolve.
// @spec openspec/specs/task-management/spec.md
import TaskDetailView from './views/tasks/TaskDetailView.vue'
// The Dashboard's My work tile, over OpenRegister's task engine.
// @spec openspec/specs/dashboard/spec.md
import MyWorkWidget from './views/widgets/MyWorkWidget.vue'
import WorkflowBoardView from './views/workflow-board/WorkflowBoard.vue'
import { leafTab } from './integrations/leafTabs.js'
// Ask whether this case already exists, before it does.
// @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
import { createCaseWithDuplicateCheck } from './services/createCaseWithDuplicateCheck.js'

// ADR-049 dissolution: the manifest Dashboard page's signal widgets (open /
// overdue / stalled cases, my tasks, task reminders, deadline alerts) and the
// two charts (cases-by-status, cases-by-type) no longer resolve through this
// registry. They are declared inline on the Dashboard page as built-in
// `object-table` (with `source.extend:["calculations"]` for the OpenRegister
// virtual calc columns daysOverdue / daysSinceActivity / daysUntilDue /
// daysUntilDeadline) and `chart` (aggregate + drilldown) widgets — CnDashboardPage
// resolves those from the shared dashboard-widget catalog, so no app-registry
// entry or `slots` mapping is needed. The self-fetching `src/views/widgets/*.vue`
// components and their `src/*Widget.js` native-dashboard entry points survive
// UNCHANGED for the native Nextcloud Dashboard (which has no manifest).
//
// ONE EXCEPTION SINCE remove-casetask: `my-work`. Its rows are not
// OpenRegister objects any more, they are engine tasks behind
// `/api/flow-tasks`, and a built-in `object-table` can only name a register
// and a schema. See the `MyWorkWidget` entry below for what has to land in
// the library before the tile goes back to being declared inline.

// Leverancier-zaakportaal external supplier portal MOVED to Portaliq (ADR-046,
// procest#162): the /leverancier Vue surface + the citizen "Mijn gemeente"
// pages (MijnZakenView / MijnNotificatiesView) are retired and re-expressed as
// the `supplier` and `citizen` audiences in
// lib/Portal/PortalContributionProvider.php. The backend supplier + zaakportaal
// services and their /api/* endpoints stay; only the in-app portal views and
// their nav/routes are removed.

// ADR-049 dissolution: the `audit-trail` registry adapter (AuditTrailWidget.vue)
// was a thin reimplementation of the library's built-in CnAuditTrailWidget.
// It has been removed — the manifest `audit-trail` widget key now resolves to
// the library built-in (BUILT_IN_WIDGETS for the slot CnWidgetGrid path, and the
// shared dashboard-widget catalog for CnDetailPage's config-grid body). The
// built-in resolves register/schema/objectId from the same detail object-context
// injects/props, so detail-page audit trails are unchanged.
//
// `version-history` (nc-vue #216, ncvue-w2-leaves-adoption): unlike
// `audit-trail`/`audit`, this integration id is NOT one of the four hardcoded
// keys in CnObjectSidebar's BUILTIN_WIDGETS map (`data`, `metadata`,
// `audit`/`audit-trail`, `object-table`), so a manifest sidebar tab cannot
// resolve it via `widgets: [{ "type": "version-history" }]` the way `audit`
// does — it would silently fail to render. It IS a real
// `builtinIntegrations` descriptor though (same registry `notes`/`calendar`/
// `forms`/`photos` live in), so it resolves the same way those leaves do:
// through `leafTab()` into a `component:` sidebar tab. See
// src/integrations/leafTabs.js.
// @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md

/**
 * V2 component registry.
 *
 * Keys must match the `component` strings used in the manifest.
 * All full-page custom routes and sidebar-tab components are kind: "page" —
 * the v2 renderer resolves any `component` key from this registry regardless
 * of whether it appears in a top-level page or in a sidebarTab entry.
 *
 * @type {Record<string, { kind: string, component: object }>}
 */
const registry = {
	// --- A refused search, said rather than rendered as an empty list. ---
	// @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
	CaseSearchRefusal: {
		kind: 'page',
		component: CaseSearchRefusal,
		_note: "Cases-page below-header slot. OpenRegister refuses a malformed _search term with 400 {error, position, term} rather than running it as a literal, precisely because a literal returns zero rows and reads as an honest empty result. useObjectStore.fetchCollection() then records the refusal on errors['dossiq-case'] and returns [] anyway, so CnIndexPage draws its empty state over it and the reader retypes a word that was never the problem. Mounted through pages[].slots because the search box is CnIndexPage's; deleted the day the library renders the store's own error above the list.",
	},

	// --- Case-list CSV/Excel export via the OR export leaf. ---
	// @spec openspec/specs/case-list-export-via-or-export-leaf/spec.md
	CaseListExportAction: {
		kind: 'page',
		component: CaseListExportAction,
		_note: 'Cases-page actions-slot "Export" menu (CSV/Excel); receives no props (CnIndexPage\'s #actions slot is unscoped). Builds the OR export-leaf URL client-side — no dossiq-side serialization (ADR-022).',
	},

	// --- One personal queue, fed by every mechanism (one-personal-queue). ---
	// @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	PersonalQueueView: {
		kind: 'page',
		component: PersonalQueueView,
		_note: 'The page holding everything waiting on the reader. A page and not an index, because it is not a list of one schema: it merges cases, engine tasks, consultations, advice, mentions, covered work and planned calendar items, and no manifest key names a set that spans four stores and a calendar. It hard-codes NO source: every group and its heading come from the declared queue sources the server resolves, which is what keeps a new mechanism from needing a page change.',
	},

	EndOfDayView: {
		kind: 'page',
		component: EndOfDayView,
		_note: "The end-of-day screen. Lists the queue candidates OpenRegister says this reader opened today, reading its per-reader read state rather than keeping a second record of who saw what. The time box is humaniq's hours leaf, placed per item and absent entirely when humaniq is not installed: a dossiq time field would become a second hours store the day humaniq arrives.",
	},

	// --- Genuine exceptions: no abstract manifest analogue. ---
	MyWorkView: {
		kind: 'page',
		component: MyWorkView,
		_note: 'Current-user case index (assignee = current uid) in card view; a thin CnIndexPage wrapper injecting the resolved uid because the stock index base-filter does not resolve the @me token.',
	},

	// --- Cases-on-map overview (case-map-overview). ---
	// @spec openspec/specs/case-map-overview/spec.md
	CasesOnMapView: {
		kind: 'page',
		component: CasesOnMapView,
		_note: "Full-screen multi-object cases-on-map overview. Markers come from OpenRegister's page-level maps-overview surface (RBAC-scoped, OR #154) and render through the lib's CnMapWidget — no bespoke Leaflet/WMS/WFS plumbing (ADR-022).",
	},

	// --- Workflow Board — Kanban with drag-to-advance status transitions. ---
	// @spec openspec/specs/dashboard/spec.md
	WorkflowBoardView: {
		kind: 'page',
		component: WorkflowBoardView,
		_note: 'Kanban board: column per non-final status, drag-to-advance via saveObject (RBAC-enforced). No declarative board page type in lib yet.',
	},

	// --- Flows (ADR-110 Decision 4). ---
	// The shared CnFlowIndexPage / CnFlowDetail surfaces over OpenRegister's
	// native flow store, scoped `app: "dossiq"` so this app sees only its own.
	// Only the SIDEBAR is an app component now. The list and the canvas are the
	// shared `flows` / `flow-detail` manifest page types (nextcloud-vue 2.19.0),
	// so this app no longer carries wrapper copies of them — the three apps that
	// did each carried the same dead `@rowClick` listener.
	// @spec openspec/specs/automatic-actions/spec.md
	FlowDetailSidebar: {
		kind: 'page',
		component: FlowDetailSidebar,
		_note: 'CnFlowSidebar in the NC app sidebar; shares useFlowStore with the canvas.',
	},

	// --- Handler vervanging/waarneming (handler-vervanging-waarneming). ---
	// @spec openspec/specs/handler-vervanging-waarneming/spec.md
	// @spec openspec/specs/handler-vervanging-waarneming/spec.md
	SubstitutionAdminView: {
		kind: 'page',
		component: SubstitutionAdminView,
		_note: 'Coordinator substitution admin + bulk reassignment + capacity action list. Coordinator-gated server-side.',
	},

	// --- The case's lifecycle on the case page (case-lifecycle-on-the-page). ---
	//
	// THREE ENTRIES USED TO LIVE HERE AND ALL THREE ARE GONE, with their
	// components: CaseTransitionsWidget, CaseHeaderRow and CaseStepsWidget. Each
	// carried a `@custom-widget-ratchet exclude` naming something the library
	// could not express, and the library expresses all three now. The identity
	// band is four configured tiles (`stat` twice for the case type and the
	// status badge, `stat` for the assignee, `countdown` for the deadline), and
	// the stepper is the `stages` widget, which reads OpenRegister
	// /available-actions and moves the case when a stage is clicked. dossiq
	// answers that endpoint through CaseActionProvider, so the moves are still
	// its own engine's, asked for in the vocabulary the library speaks.
	// --- The case type's effective blueprint (case-type-authoring-extras). ---
	// @spec openspec/specs/case-types/spec.md
	CaseTypeBlueprintWidget: {
		// @custom-widget-ratchet exclude the merged statuses/results/attributes of a type and its parent exist only in CaseTypeResolver; an object-list can ask OpenRegister for `caseType = @objectId` and nothing else, so a child type would render three empty tables
		kind: 'widget',
		component: CaseTypeBlueprintWidget,
		_note: 'CaseTypeDetail: what the type actually offers, over /api/case-types/{id}/blueprint, with an Inherited badge on every row that came from the parent and a Shared badge on every attribute that belongs to no type. In the LAYOUT rather than inside a tab strip on purpose: a type:"custom" widget named as a tab CHILD resolves by registry type, finds nothing and renders an empty panel without logging anything.',
	},
	// @spec openspec/specs/zaaktype-versioning/spec.md
	CaseTypePublishDialog: {
		kind: 'modal',
		component: CaseTypePublishDialog,
		_note: 'CaseTypeDetail Publish: reads /publish/validate FIRST and shows the findings instead of a note field when there are any, so a person about to be refused is told before being made to write a note they would lose. A declarative api-call cannot express that order.',
	},
	// @spec openspec/specs/workflow-import-export/spec.md
	CaseTypeImportDialog: {
		kind: 'modal',
		component: CaseTypeImportDialog,
		_note: "CaseTypeDetail Import: the import takes a FILE, and neither a header action's confirm gate (a plain dialog with no fields) nor an api-call (a JSON body) can carry a multipart upload. Declared declaratively it would be a button that cannot do the one thing it is for.",
	},
	// @spec openspec/specs/workflow-import-export/spec.md
	CaseTypeDuplicateDialog: {
		kind: 'modal',
		component: CaseTypeDuplicateDialog,
		_note: 'CaseTypeDetail Duplicate: posts the copy, reads the new id out of the answer and ROUTES there. An api-call refreshes the page you are already on, so a person who asked for a copy would be left looking at the original with no clue where the copy went.',
	},
	// @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	CaseTypeNewVersionDialog: {
		kind: 'modal',
		component: CaseTypeNewVersionDialog,
		_note: 'CaseTypeDetail New version: posts the next version and ROUTES to the draft, for the reason Duplicate is a dialog. The tasks called for a declarative api-call, and an api-call refreshes the page you are already on, so the person who asked for a new version would be left on the old one with the draft nowhere in sight. It also says what a version IS before making one: the gesture beside it is Duplicate, and a duplicate is a second case type while a version is this one later on.',
	},
	// @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	CaseVersionMoveDialog: {
		kind: 'modal',
		component: CaseVersionMoveDialog,
		_note: 'CaseDetail Actions menu: move this case to another version of its own case type. The PREVIEW is why it is a modal and not a confirm gate: a case is pinned to the version it was filed under because its status is a row only that version holds, so the person moving it is shown the landing status and the statuses and fields the other version adds and drops, including the dropped ones this case has answered. It derives NONE of that: canMove and every refusal sentence come from the server, so the dialog cannot disagree with the write.',
	},
	// @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
	CrossDomainLookupDialog: {
		kind: 'modal',
		component: CrossDomainLookupDialog,
		_note: 'Is this household already known to another domain. A modal and not an api-call because the GROUND is the act: it is chosen before the answer rather than filled in afterwards, which is what makes the lookup deliberate and what makes the log mean something when the person asks what was looked up about them. The answer is three facts, that an open case exists, in which domain and who to call, and there is no show-more and never will be: purpose limitation between Wmo, Jeugdwet and Participatiewet does not allow the 360 view that gap row 5.4 asks for. The grounds come from the server, because a second copy here would drift and a ground on screen the service does not know is a choice a consulent makes and is then refused for.',
	},
	// @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	CaseRebindDialog: {
		kind: 'modal',
		component: CaseRebindDialog,
		_note: 'CaseDetail Actions menu: move this case to a DIFFERENT case type, which the version move beside it cannot do. It is a modal and not a confirm gate because the MAPPING is the act: across two case types a status name means nothing, so the landing status is asked for rather than derived, and so is every property the target requires in that status and the case does not carry. The dialog derives no verdict: canRebind, the missing names and every refusal come from the server, and the group check lives in the service rather than in this button.',
	},
	// @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
	CaseHandoverDialog: {
		kind: 'modal',
		component: CaseHandoverDialog,
		_note: 'CaseDetail Actions menu: hand this case to another team. A modal rather than an api-call because the act takes a team, a reason and the Awb 2:3 declaration, and an api-call carries a fixed body. The declaration is the field that cannot be defaulted: on, the applicant is told the case moved and to whom; off, nothing is sent, because an internal move between two teams of one bestuursorgaan is our arrangement and not their news.',
	},

	// @spec openspec/specs/status-transition-engine/spec.md
	CaseLifecycleActionDialog: {
		kind: 'modal',
		component: CaseLifecycleActionDialog,
		_note: 'One reason dialog for Suspend, Resume, Extend term and Reopen; the manifest header actions open it with `props.action`. It reads /lifecycle first, so a gesture the case type forbids says so before the POST rather than after it.',
	},
	// @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	CaseLifecycleMenuDialog: {
		kind: 'modal',
		component: CaseLifecycleMenuDialog,
		_note: 'One menu holding every lifecycle act on the case (REQ-LIFE-10). The acts used to sit in three places, each gated differently, so a handler found out what they could do by trying. It merges /available-transitions, /lifecycle and /acts into one list and DERIVES NOTHING: every disabled and every reason is copied from a server answer. An act the handler may not perform is SHOWN disabled with the reason, never hidden, because the reason is what tells them who to ask. CaseLifecycleActionDialog stays: the stages widget opens it directly for Resume, which is the one gesture a suspended case needs in front of the handler rather than behind a menu.',
	},

	// @spec openspec/changes/case-merge/specs/case-management/spec.md
	CaseMergeDialog: {
		kind: 'modal',
		component: CaseMergeDialog,
		_note: 'Merging two cases into one (REQ-CM-37). The survivor is searched for and picked, never defaulted, because the reversal window is seven days and a preselected survivor is one that gets confirmed. The refusals are the server\'s and are shown verbatim beside their code: a signed beschikking and an already merged case are two different answers with two different ways out. WHY THE ACTION IS HIDDEN ON A CLOSED CASE, moved here from the manifest entry: a closed case is a record of what was decided. The other two refusals, a signed beschikking and a case that was already merged, are the server\'s and arrive as a sentence in the dialog; hiding them in the header too would leave a handler wondering why an act they were told about is not there. The manifest entry carries no `_note` because `$defs/action` sets `additionalProperties: false`, which is the same reason CasePlanFollowUpDialog above records.',
	},

	// --- Copy a case, from its own page (case-actions-menu, row A24). ---
	// @spec openspec/specs/case-management/spec.md
	CaseCopyDialog: {
		kind: 'modal',
		component: CaseCopyDialog,
		_note: "CaseDetail Actions menu: what the copy is called, and whether the source's documents come along. NOT CnCopyDialog, which the design named: the library's 2.41.0 copy dialog offers three naming PATTERNS over a fixed name and carries no slots at all, so there is nowhere to put the Include documents checkbox and no way to type a title that is not one of the three. It reads the case from the ROUTE, because an open-modal action forwards its props verbatim and `@objectId` would arrive as that literal string. Deleted the day nextcloud-vue ships a `copy` header-action type taking a field list and an endpoint (tasks 1.4).",
	},

	// --- The case as Open Register stored it (admin-inspect-entry, row 2.22). ---
	// @spec openspec/changes/admin-inspect-entry/specs/case-management/spec.md
	CaseRawDataDialog: {
		kind: 'modal',
		component: CaseRawDataDialog,
		_note: "The Raw data entry of the CaseDetail Inspect action. NOT CnObjectMetadataModal, which the design named: that component takes a REQUIRED `objectData` object and an open-modal action forwards its props verbatim, so the manifest has no way to hand it the case, and what it renders is the `@self` block rather than the record, so it would answer who owns the case and never show a stored property. Deleted the day nextcloud-vue ships a metadata modal that resolves its own object from the page context. It reads the case from the ROUTE, for the reason CaseCopyDialog does. Hiding it from a handler is an affordance and not a control: the fetch goes to OpenRegister, which refuses on its own, and the dialog prints the refusal rather than opening empty.",
	},

	// --- Start a sub-process for the case (case-actions-menu, row A25). ---
	// @spec openspec/specs/workflow-definition-engine/spec.md
	CaseStartFlowDialog: {
		kind: 'modal',
		component: CaseStartFlowDialog,
		_note: "CaseDetail Actions menu: the flows this case's TYPE lists in startableFlows, and Run. The run is posted straight to OpenRegister's /api/flows/{id}/run with the case as `{uuid, register, schema}` — the three keys FlowRunRow reads — so it lands in the Flow runs widget beside it and dossiq stores no copy of a run (ADR-022). The Start entry is hidden by the case's materialised `hasStartableFlows`; the dialog still says so when the list comes back empty, because the gate is a save-time value and a case type edited since the last case save has not been recomputed yet.",
	},

	// --- The Related cases tab, with the follow-ups still to come
	//     (case-actions-menu, row A26). ---
	//
	// KEYED BY THE WIDGET'S `type`, NOT BY A COMPONENT NAME, for the same
	// reason `case-task-pane` is: this is a child of the `case-panels` tabs
	// widget, and a tab child has no layout grid item and
	// therefore no `widget-<id>` page slot. CnTabsWidget resolves a tab child
	// through `cnRegistry[widget.type]` and renders nothing, silently, when no
	// key answers.
	// @spec openspec/specs/workflow-definition-engine/spec.md
	'case-related-planned': {
		// @custom-widget-ratchet exclude a planned follow-up is a SCHEDULED FLOW and not a case, so the `related` widget cannot list it: it reads related OBJECTS. The widget wraps the library's own CnRelatedObjectsWidget and only adds an extraSections group, so the built-in related content is unchanged. Deleted the day OpenRegister's related widget can include scheduled flows by subject (tasks 3.3)
		kind: 'widget',
		component: CasePlannedWidget,
		_note: 'CaseDetail Related cases tab: what is related to this case, and what is about to be. The planned rows come from /api/case/{id}/planned, which lists the scheduled flows for this case that have not fired; once one fires its case is an ordinary related case and the row is gone. The Plan follow-up button sits here as well as in the Actions menu, because the tab is where a handler is already looking at what this case is connected to.',
	},

	// --- The adaptive case plan, over OpenRegister's case layer
	//     (retire-cmmn-caseplanstate, group 1). ---
	//
	// Same widget slot as the retiring CMMN panel, new data source: the plan is
	// rows in `openregister_case_items` read over /api/cases, not a blob this
	// app decodes. The local engine and its `casePlanState` are untouched here;
	// they retire in groups 3 to 5, gated on a clean drain report.
	// @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md
	CasePlanPanel: {
		// @custom-widget-ratchet exclude the adaptive plan is a TREE of plan items with a six-state lifecycle and per-item transition actions, living in OpenRegister's case layer rather than in the case object; no declarative widget reads /api/cases, and an object-list over the case would render neither the nesting nor the transitions. Deleted the day the manifest vocabulary has a case-plan widget type
		kind: 'widget',
		component: CasePlanPanel,
		_note: 'CaseDetail: the stages, tasks and milestones OpenRegister holds for this case, with enable, complete and stop per item. Fails CLOSED on an unreachable case layer: an error with a retry, never an empty plan, because an outage and a finished case look identical from the browser and only one of them is safe to act on.',
	},

	// --- The case's archival future, as openregister decided it. ---
	// The archiving process lives in openregister (decision D7): this panel reads
	// `@self._retention` and derives nothing. A second derivation in the browser
	// would eventually disagree with the stored one, and a records manager reading
	// a disposal date has no way to tell which of the two they are looking at.
	// @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
	// Keyed by TYPE, not by component name. A tab child resolves through
	// `resolveRegistryRenderer`, which reads `cnRegistry[widget.type]` and
	// nothing else: a page `slots` map is read by CnDashboardPage's grid and
	// never reaches a widget inside a tab panel. The Archiving tab shipped as
	// `type: "custom"` with a `widget-case-archival` slot, and drew an empty
	// panel with no warning: the same failure `case-timeline-pane` below
	// records having shipped twice.
	// --- Who has held this case, and who is asking for it (CaseDetail). ---
	// Keyed by TYPE, like every other tab child: a tab panel resolves through
	// `resolveRegistryRenderer`, which reads `cnRegistry[widget.type]` and
	// nothing else. A page `slots` map never reaches a widget inside a tab, and
	// a widget that shipped as `type: "custom"` would draw an empty panel with
	// no warning at all.
	// @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
	'case-custody-pane': {
		// @custom-widget-ratchet exclude the panel joins TWO surfaces that no declarative widget spans: the chain of holdings, whose rows are only meaningful as a JOIN between one holding's end and the next one's start, and the takeover requests beside it, whose answer is a POST carrying a required reason. A data widget builds its fields from one schema's properties and would draw each holding as a row of values with no way to show the join. Deleted the day the manifest vocabulary has a chain widget over a dated record
		kind: 'widget',
		component: CaseCustodyPanel,
		_note: 'CaseDetail Custody tab: every period this case was held, with both ends of each holding, and the takeover requests beside them with accept and refuse. Fails CLOSED on a refusal: a reader who may not open the case is told so, never shown an empty chain, because "this case never changed hands" and "you may not see this" look identical from an empty list. The panel writes no holding: an accept answers a REQUEST and the move opens the holding server-side.',
	},

	'case-archival-pane': {
		// @custom-widget-ratchet exclude `@self._retention` is metadata attached on the render path, not a stored property, so a data widget builds its fields from the schema's properties and renders every one of them blank; the nomination also carries a rule and a reason that are prose beside a value, and the recompute gesture is a POST carrying a required reason. Deleted the day the manifest vocabulary has a retention widget type
		kind: 'widget',
		component: CaseArchivalPanel,
		_note: "CaseDetail Archiving tab: the appraisal, the disposal date, the retention period, the selectielijst row, the nomination with the rule that produced it, and the outcome once a reviewer has decided one. Fails CLOSED on an unreachable openregister: an error with a retry, never an empty archival block, because an outage and a case with no archival future look identical from the browser. An unnominatable case is drawn apart from a case nobody has closed yet, because only one of the two is somebody's problem today.",
	},

	// --- A reviewer's own pending archival decisions (My Work). ---
	// `/archival/reviews/pending` reads the session user id, so nothing is narrowed
	// in the browser. A filter over a wider list would be a weaker thing wearing
	// the same label.
	// @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
	MyArchivalReviews: {
		// @custom-widget-ratchet exclude a destruction list entry is not a dossiq object: it lives on openregister's destruction list and no declarative widget reads that surface, and each of the three answers carries a reason, with retain also carrying a new date, collected before the post. Deleted the day the manifest vocabulary has a worklist widget over a leaf endpoint
		kind: 'widget',
		component: MyArchivalReviews,
		_note: 'My Work: the destruction list entries the signed-in person has to sign off, with destroy, retain and transfer, each carrying a reason. An answered entry leaves the list without a reload. An empty list reads as nothing to sign off; a failed read reads as an error with a retry, because the two look identical from an empty array.',
	},

	// --- Plan a follow-up case (case-actions-menu, row A26). ---
	// @spec openspec/specs/workflow-definition-engine/spec.md
	CasePlanFollowUpDialog: {
		kind: 'modal',
		component: CasePlanFollowUpDialog,
		_note: 'CaseDetail Actions menu and the Related cases tab: a case type, a date and a title, posted to /plan, which writes ONE scheduled flow creating the case on that date. The earliest date is tomorrow, because a schedule fires on a cron minute and a follow-up planned for today would fire in a few hours or not at all depending on the clock. Single-shot is kept by PlannedFollowUpSweepJob, not by the cron: five cron fields cannot say "once" or "three times". A Repeat picker turns it into a series (planned-case-series): the recurrence becomes the cron fields, the end becomes the sweep\'s stop rule, and the Related tab grows a series row with a Stop series action. The form lives here and not in the manifest: an `open-modal` header action carries a target and props only, and the five fields (case type, date, title, Repeat, Ends) are bound to each other, since the end fields appear only once a repeat is chosen and no `visibleWhen` on a header action can say that. What the manifest does decide is that the gesture is a modal rather than a `handler`, because a handler action resolves `action.handler` against `effectiveManifest.actions`, a JSON map that cannot hold a function, so the entry would warn to the console and do nothing when clicked. The manifest entry itself carries no `_note`: the v2 schema sets `additionalProperties: false` on a header action, so the rationale belongs in this file.',
	},

	// --- Remind a colleague about this case (case-reminder-as-task, row 8.4). ---
	// @spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md
	RemindDialog: {
		kind: 'modal',
		component: RemindDialog,
		_note: "CaseDetail header action: who, when and what, creating an ENGINE task on the case with kind `reminder`. There is no reminder record and no dossiq job, and that is the design rather than an omission: the engine's due window already answers what is coming up and its assignment notification already tells the colleague, so a second clock in dossiq would disagree with the badge the engine decided the first time one of them was wrong. The kind is the only mark a reminder carries, it is an indexed column on openregister_tasks (openregister#3863), and the Tasks sidebar facets on it. Nothing else treats the value specially, which means a reminder written with the wrong spelling is still created, assigned and notified and is simply missing from the one lens that exists to find it; `REMINDER_KIND` in src/utils/reminderHelpers.js is the single spelling all three surfaces read. The form lives here and not in the manifest because an `open-modal` header action carries a target and props only, and it reads the case from the ROUTE because open-modal forwards props verbatim, so `@objectId` would arrive as that literal string. Who defaults to the signed-in user: most reminders are the one you set for yourself. The manifest entry carries no `_note`: the v2 schema sets `additionalProperties: false` on a header action.",
	},

	// --- The duplicate warning at intake (duplicate-warning-at-intake). ---
	// @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
	caseCreateWithDuplicateCheck: {
		kind: 'create-override',
		handler: createCaseWithDuplicateCheck,
		_note: "Named by `createOverride` on every `new-case` open-form action. It owns the persist, so it can ask OpenRegister whether a case like this one already exists BEFORE the case is written, and show the matches with a link to each. It is not the enforcement: DuplicatePolicy refuses a blocked create on the pre-persist event, so the mail intake, an import and any integration are refused the same way. A createOverride runs on the press rather than on the keystroke, which is the one part of REQ-FCF-10 this seam cannot give: disabling the library dialog's own Create button needs a `beforeConfirm` hook in @conduction/nextcloud-vue.",
	},

	// --- Initiator selection + display (brp-kvk-register-sets). ---
	// @spec openspec/specs/initiator-selection/spec.md
	InitiatorPicker: {
		kind: 'form-field',
		component: InitiatorPicker,
		appliesTo: ['case.requester', 'contactmoment.contact', 'role.representedParty'],
		_note: 'Cross-source initiator picker (Person=brpPerson / Company=kvkCompany register sets via the object store, Contact=core contactsmenu with graceful empty state). Bound to case.requester through fieldOverrides on the Dashboard new-case action and the CaseDetail case-core overrides. Also used inline by InitiatorPickerModal in the StartCaseWidget create flow. NOTE: a form-field entry is validated by CnAppRoot but not yet MOUNTED into CnFormDialog by @conduction/nextcloud-vue 2.41.0 — the manifest binding is the declaration, and until the library mounts it the resolved ns#Requester provider renders the field as its own object picker.',
	},

	// --- Which role a party takes on the case (gemachtigde-role-on-every-case-type). ---
	// @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
	RoleTypePicker: {
		kind: 'form-field',
		component: RoleTypePicker,
		appliesTo: ['role.roleType'],
		_note: "The Add party form's Role field. The $ref on role.roleType makes the library offer EVERY roleType row this instance holds, including the seats of case types this case is not, and it cannot skip the generic Gemachtigde a type declares itself. This narrows the list to the case type's own rows followed by the generic ones, and drops a generic row whose key the type already claims. The ordering and the deduplication live in src/services/roleTypeOptions.js so they are testable without mounting anything. Same standing limitation as InitiatorPicker above: @conduction/nextcloud-vue 3.2.0 validates a form-field entry (CnAppRoot requires `appliesTo`) but does not yet mount one into the form dialog, so until it does the field falls back to the library's own object picker and this is the declaration of what it should be.",
	},

	// TaskWaitingCaseSection is NOT a registry entry any more, and neither is
	// TaskCaseCard. Both were resolved through TaskDetail's `slots` map while
	// that page was `type: "detail"`; the page is now `type: "custom"` and
	// mounts them as plain child components of TaskDetailView, the same way
	// WorkflowTab mounts WorkflowEditor. A registry entry no manifest names
	// resolves for nobody and is dead configuration.

	// --- Generate document, the CaseDetail header action (documents-on-the-case). ---
	// @spec openspec/specs/beschikking-generatie/spec.md
	BeschikkingComposerDialog: {
		kind: 'modal',
		component: BeschikkingComposerDialog,
		_note: "Picks a template from TemplateController#index and files the rendered letter on the case through MergeTemplateHandler with no targetField. Opened by the CaseDetail `generate-document` header action as `type: open-modal`, the interim for a `run-action` the library cannot dispatch yet (design D4). The action passes `open: true` because CnAppRoot mounts a registry modal with the action's props verbatim, and the dialog renders on `open`; `caseId` is passed for the same reason and IGNORED when it still holds the unresolved `@objectId` token, because open-modal resolves no tokens.",
	},

	// --- The case file as a tab on the case page (documents-on-the-case). ---
	// The tab itself is `type: "object-list"` now (documents-on-the-case
	// task 2.2), a library built-in resolved by nextcloud-vue and not listed
	// here -- only the two dialogs its `dropZone`/upload and `bulkActions`
	// dispatch to are dossiq's own.
	// @spec openspec/specs/document-zaakdossier/spec.md
	// @spec openspec/specs/document-zaakdossier/spec.md
	DocumentMetadataDialog: {
		kind: 'modal',
		component: DocumentMetadataDialog,
		_note: "Upload metadata dialog. Opened by the Documents tab's object-list `dropZone`/upload-button action as `type: open-modal`, which hands over `props.files` (the dropped or picked File[]) the same way a header action's `open-modal` props arrive -- verbatim, no `@`-token resolution. `caseId` is passed for the same reason BeschikkingComposerDialog's is, and falls back to the route when it still holds the literal token. Self-sufficient: fetches the informatieobjecttype catalog and performs the upload itself, since there is no parent DossierTab any more to do either.",
	},
	// @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
	FileRequestDialog: {
		kind: 'modal',
		component: FileRequestDialog,
		_note: "Ask a party of the case for a file. Opened by the Files tab's `newActions` entry as `type: open-modal`, so the registry mounts it with the action's props (the folder's path and whatever the manifest declared) and nothing else; it reads the case from `caseId` or the route and fetches the parties itself. The recipients are the people linked to the case (people-on-the-case), because Nextcloud's own file request can name nobody: it asks for an address a handler has to know by heart. A party with no address is listed and disabled with the reason.",
	},
	BulkDocumentActionDialog: {
		kind: 'modal',
		component: BulkDocumentActionDialog,
		_note: 'Mark final / Change confidentiality / Download ZIP on a Documents-tab selection, one dialog in three `mode`s (mirrors BulkTransitionDialog). Opened by the object-list `bulkActions` entries as `type: open-modal`; CnObjectListWidget merges `props.selectedIds` onto the declared props the same way a drop merges `props.files`.',
	},
	VersionHistoryPanel: {
		kind: 'modal',
		component: VersionHistoryPanel,
		_note: 'Version history for one dossier document, over the Nextcloud Files versions WebDAV API. Opened by the object-list `rowActions` Versions entry as `type: open-modal`; CnObjectListWidget merges `props.row` (the clicked zaakinformatieobject row, `informatieobject` inlined by `content.extend`) onto the declared props (nextcloud-vue#1117) -- an open-modal row action otherwise carries no per-click information at all. Self-sufficient: reads the informatieobject off `row.informatieobject` and the signed-in user via `getCurrentUser()`, since there is no parent DossierTab any more to pass either down.',
	},

	// --- The inline task pane on the case page (task-on-the-case A06). ---
	//
	// KEYED BY THE WIDGET'S `type`, NOT BY A COMPONENT NAME, because
	// `case-tasks` is a child of the `case-panels` tabs widget. The change
	// design called for `type: "custom"` behind the page slot
	// `widget-case-tasks`, the way TaskDetail resolves `widget-task-waiting-case`
	// below. That path exists only for a widget in the page's `layout`:
	// CnDetailPage renders one `widget-<id>` slot per GRID item, and a tab child
	// is deliberately absent from `layout` (a layout entry renders it twice).
	// CnTabsWidget renders its children through CnDetailWidgetHost, which picks a
	// renderer from `cnRegistry[widget.type]` and, failing that, renders NOTHING
	// and logs nothing. So the registry key is the type the manifest names, which
	// is the injection CnAppRoot provides and the shape the library documents for
	// an app's own widget types.
	// @spec openspec/specs/task-management/spec.md
	'case-task-pane': {
		// @custom-widget-ratchet exclude blocked: nextcloud-vue 2.41.1 CnObjectListWidget has no rowActions and no lifecycle column, so a lifecycle button cannot be put inside a row from the manifest; the widget returns to type object-list and this entry is deleted the moment the library ships one (https://github.com/ConductionNL/nextcloud-vue/issues/1033)
		kind: 'widget',
		component: CaseTaskPane,
		_note: 'CaseDetail Tasks tab: the first open task of the case with the lifecycle buttons OpenRegister answers for it, a toast on completion and the next open task in its place. No built-in fits: CnObjectListWidget accepts register/schema/filter/sort/limit/columns/rowRoute/prompt/emptyText/viewAllRoute/viewAllQuery and nothing else, has no rowActions and no per-row slot, and a config key it does not declare is dropped in silence. Interim by construction, and the e2e asserts on the tab and the button labels rather than on this component so it survives the swap back.',
	},

	// --- Case panel tabs that were sidebar tabs first. ---
	//
	// A tab child renders by TYPE: CnDetailWidgetHost picks a renderer from
	// `cnRegistry[widget.type]` and, failing that, renders NOTHING and logs
	// nothing. Both components below were registered only as `kind: 'page'`
	// for the sidebar, so naming them from a body tab silently drew an empty
	// panel. `case-notes-pane`, registered further down, shipped in that state
	// for a day before #2631 fixed it.
	//
	// Keyed by the TYPE the manifest names, like `case-task-pane` above and
	// unlike the `component:` entries further down, which the sidebar resolves
	// by component name instead.
	// @spec openspec/specs/case-dashboard-view/spec.md
	// @spec openspec/specs/case-dashboard-view/spec.md
	'case-email-pane': {
		// @custom-widget-ratchet exclude the surface is a LEAF, not a collection of OpenRegister objects: CaseEmailTab consumes the mail leaf and calls prefillDraft to compose, and a built-in object-list takes a register and a schema, which email threads do not have. There is no `integration` id for mail either, so `type: "integration"` cannot reach it. This entry is deleted the day the library ships a mail widget type or OpenRegister exposes an email integration leaf
		kind: 'widget',
		component: CaseEmailTab,
		_note: 'The Email tab of the case panels: correspondence linked to the case, consuming the mail leaf. Was a sidebar tab; moved into the strip so the two logs a handler reads, email and contact moments, sit beside each other rather than one in each chrome.',
	},

	// @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	'case-conversations-pane': {
		// @custom-widget-ratchet exclude the surface is an ACT, not a collection of OpenRegister objects: it starts a Talk room, declares the case major and opens the one channel that declaration made, and a built-in object-list takes a register and a schema and offers no button. The records it lists live on the case itself as `case.conversations`, which no widget type can read as a collection either. Deleted the day the library ships a widget type that posts to an app endpoint and renders the array a field holds
		kind: 'widget',
		component: CaseConversationsPanel,
		_note: 'The Live conversation section of the Communication tab: start a conversation in Talk from any case, see what the case recorded of the ones already held, and declare the case major. The hoorzitting reaches the same mechanism through HearingService; this is the surface for every other case. Absent Talk, it says so rather than offering a button that cannot work.',
	},

	// @spec openspec/specs/case-dashboard-view/spec.md
	'case-decisions-pane': {
		// @custom-widget-ratchet exclude the decisions surface is decidiq's own integration leaf, reached through leafTab('decidesk-decisions'), so there is nothing in this repository for a built-in widget to read: no register, no schema, and no `integration` id that resolves it as a widget rather than as a sidebar tab. It moved from a `component:` sidebar tab to a tab child and needs a TYPE to render by; the definition is otherwise the same component. Deleted the day a leaf can be placed as a built-in widget
		kind: 'widget',
		component: BesluitvormingLeafTab,
		_note: 'The Decisions tab of the case panels: the decidiq decisions leaf (ADR-019/ADR-022). Was a sidebar tab. A decision is a case OUTCOME rather than correspondence or a related case, so it earns a tab rather than a section of one.',
	},

	// --- The case's locations, as a map on the Data tab. ---
	// @spec openspec/specs/case-dashboard-view/spec.md
	'case-location-map': {
		// @custom-widget-ratchet exclude blocked: the library `map` widget cannot be scoped to one case. `markers.dataSource.{register,schema}` fetches the register with `_limit` and no filter, and `markers.dataSource.url` is not token-resolved, so `@objectId` would be sent literally. Either route plots every case's locations on this case's page. This entry is deleted and the manifest returns to `type: "map"` the moment the library takes a filter (https://github.com/ConductionNL/nextcloud-vue/issues/1141)
		kind: 'widget',
		component: CaseLocationMap,
		_note: 'Replaces the Locations list that was the second section of the retired Objects and locations tab. `case-location` already carries latitude and longitude, so the addresses were a table of coordinates nobody could picture. A row with no usable pair is skipped rather than plotted at (0, 0), which is open water and looks like a real pin.',
	},

	// --- The task page (`/tasks/:id`), over the engine (remove-casetask 2.1). ---
	// @spec openspec/specs/task-management/spec.md
	TaskDetailView: {
		kind: 'page',
		component: TaskDetailView,
		_note: "The task page reads OpenRegister's task ENGINE, which is not an OpenRegister object, and there is no typed page that can. CnDetailPage takes objectType/objectId and resolves them through the object store; it has no entitySource mode, so a type:detail page can only bind a register and a schema, and the schema it bound is the one remove-casetask deletes. The route, the page id and every deep link are unchanged. It mounts TaskCaseCard and TaskWaitingCaseSection as plain children, reads the notes, appointments and history leaves from the task-anchored endpoints (the object-anchored library widgets have no object to read), and drives the lifecycle with the engine's own verbs through invoke(uuid, verb): CnLifecycleActions asks /api/objects/{uuid}/available-actions, which 404s for a task.",
	},

	// --- The Dashboard's My work tile (remove-casetask 2.3). ---
	//
	// Keyed by COMPONENT NAME, like TaskCaseCard above and unlike
	// `case-task-pane`: `my-work` is a widget in the Dashboard page's own
	// `config.widgets`, so it has a grid item, CnDashboardPage renders a
	// `widget-my-work` slot for it, and `pages[Dashboard].slots` maps that
	// slot name to this key.
	// @spec openspec/specs/dashboard/spec.md
	MyWorkWidget: {
		// @custom-widget-ratchet exclude the rows are not OpenRegister objects: an engine task lives behind /api/flow-tasks with no register and no schema, and every built-in table widget takes exactly those two, so no configuration of object-table can address this list at all; nextcloud-vue 2.46.0 ships a `tasks` entity source for INDEX pages (src/composables/indexSources.js) and no widget equivalent, which is the gap this entry stands in for
		kind: 'widget',
		component: MyWorkWidget,
		_note: 'Dashboard My work tile: your open tasks from OpenRegister\'s task engine, soonest due first, with a days-left column and a red row once a deadline has passed. INTERIM. It is a component rather than a built-in `object-table` only because nextcloud-vue has no task source for widgets: 2.46.0 gives an INDEX page `entitySource: "tasks"` and gives a widget nothing, so the manifest cannot name this list. TARGET: back to a generic widget the day the library grows that source, at which point the `content` block the manifest still carries is what it goes back to reading and this entry is deleted. The e2e asserts on the tile and its rows rather than on this component, so it survives the swap back.',
	},

	// --- Case assistant via Hermiq (case-assistant-via-hermiq). ---
	// @spec openspec/specs/case-assistant-via-hermiq/spec.md

	// --- CMMN adaptive case plan (cmmn-adaptive-case). ---
	// @spec openspec/specs/cmmn-adaptive-case/spec.md

	// --- Besluitvorming workflow views. ---
	// The agenda compiler and the vergadering detail view were retired: decidiq
	// owns agenda-building and meetings, and surfaces them on a case through the
	// `decidesk-decisions` integration leaf rather than through pages here.
	BesluitPublicatiePanel: {
		kind: 'page',
		component: BesluitPublicatiePanel,
		_note: 'DROP/LVBB publication status + retry; embeddable as a case-detail sidebar tab component.',
	},

	// --- Anonymous-public routes (no auth, no main menu). ---
	// The bespoke public case-view (PublicCaseView) was removed by
	// migrate-public-share-to-shares-leaf: its password/comment/contribute
	// model was tied to the bespoke share-token controller. The citizen
	// "track your case" status page (PublicStatusPage) now resolves through
	// OpenRegister's shares-leaf #[PublicPage] case-token endpoint (ADR-022).
	PublicAppointmentPage: {
		kind: 'page',
		component: PublicAppointmentPage,
	},
	PublicStatusPage: {
		kind: 'page',
		component: PublicStatusPage,
	},
	// Remote-org accept/reject for a federated zaakoverdracht — authenticated
	// via the transfer-scoped OR federated-share bearer token in the URL,
	// not a local session (federated-case-collaboration).
	PublicFederatedTransferPage: {
		kind: 'page',
		component: PublicFederatedTransferPage,
	},

	// --- Detail-tab components (sidebar component: entries). ---
	// These resolve when a sidebarTab uses `component: "<key>"` instead of
	// a `widgets[]` array. CnDetailPage injects the resolved component into
	// the tab panel slot.
	CaseTasksTab: {
		kind: 'page',
		component: CaseTasksTab,
		_note: 'Tasks where task.case === parent.id',
	},
	// CaseDecisionsTab was retired by dossiq-decisions-to-decidiq: it offered
	// create/edit/delete of local decision records while mounted on no page.
	// Decisions are authored in decidiq (besluitvorming leaf); the read-only
	// case-decisions widget displays the outcomes stored on the case.
	CaseDocumentsTab: {
		kind: 'page',
		component: CaseDocumentsTab,
		_note: 'Documents where document.case === parent.id',
	},

	// --- Deelzaak (sub-case) full-page views — manifest routes. ---
	// @spec openspec/changes/deelzaak-support/tasks.md#T05
	DeelzaakList: {
		kind: 'page',
		component: DeelzaakList,
		_note: 'Sub-case list for a parent case; mounted under /cases/:id/deelzaken.',
	},
	// @spec openspec/changes/deelzaak-support/tasks.md#T06
	DeelzaakDetail: {
		kind: 'page',
		component: DeelzaakDetail,
		_note: 'Sub-case detail with parent breadcrumb; mounted under /cases/:parentId/deelzaken/:id.',
	},

	// --- Case-email sidebar tab — leaf-first per ADR-022. ---
	// @spec openspec/changes/case-email-integration/tasks.md#T12
	CaseEmailTab: {
		kind: 'page',
		component: CaseEmailTab,
		_note: 'Sidebar tab that surfaces email correspondence linked to the case; consumes the email leaf for display + uses prefillDraft for compose.',
	},

	// --- Case-appointment (internal calendar) sidebar tab — leaf-first per ADR-022. ---
	// The former bespoke LocalBackend scheduling surface is replaced by OR's
	// `calendar` integration leaf (CalendarProvider): the leaf owns event
	// list/create/link/unlink/delete and fetches straight from OR using the
	// objectId/register/schema/apiBase that CnObjectSidebar injects. Dossiq
	// keeps only zaak-specific metadata + external Qmatic/JCC (ADR-022 exception).
	// @spec openspec/changes/migrate-appointments-to-calendar-leaf/tasks.md#P1.2
	CalendarLeafTab: {
		kind: 'page',
		component: leafTab('calendar'),
		_note: 'OR calendar integration leaf (CnCalendarTab) surfaced on the case detail; replaces the bespoke LocalBackend appointment UI (ADR-022).',
	},

	// --- Version history sidebar tab (ncvue-w2-leaves-adoption, nc-vue #216). ---
	// Field-by-field diff viewer over the same audit-trail data as the
	// existing "audit" tab, resolved via leafTab('version-history') because
	// CnObjectSidebar's BUILTIN_WIDGETS map does not carry a
	// 'version-history' key (see the ADR-049-adjacent comment above). Wired
	// as a `component:` sidebar tab beside "audit" on every detail page's
	// manifest sidebar.tabs[] (src/manifest.json).
	// @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	VersionHistoryLeafTab: {
		kind: 'page',
		component: leafTab('version-history'),
		_note: 'nc-vue built-in version-history integration leaf (CnVersionHistory) surfaced on every detail page sidebar beside the existing audit-trail tab.',
	},

	// --- Notes sidebar tab with @mention notifications (ncvue-w2-leaves-adoption, nc-vue #207). ---
	// The existing "case-notes" CaseDetail body widget renders CnNotesCard,
	// which predates @mention and does not emit it. This sidebar tab renders
	// the full CnNotesTab (which does emit `mention`) and forwards the event
	// to dossiq's own notification endpoint — see CaseNotesTab.vue for the
	// full rationale. Wired as a `component:` sidebar tab on CaseDetail.
	// @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	// --- The requester's projection, kept in step without a card. ---
	//
	// The initiator card left the page on 2026-09-12 (Ruben), but the card
	// was also where a case saved with only the `requester` reference got its
	// projection (type, source id, display name) written back, and the case
	// list's Requester column and filter read that projection. This is the
	// back-fill alone, mounted headless through the page's `actionsComponent`
	// slot, which is the one place a detail page mounts a component of ours
	// on load without giving it a grid cell.
	// @spec openspec/specs/initiator-display/spec.md
	RequesterProjection: {
		kind: 'widget',
		component: RequesterProjection,
		_note: 'CaseDetail actions slot, headless: fills initiatorType, initiatorSourceId and initiatorDisplayName from the canonical requester uuid when the case carries the reference and no projection. Renders nothing.',
	},
	CaseNotesTab: {
		kind: 'page',
		component: CaseNotesTab,
		_note: "Mention-aware notes sidebar tab: wraps the library CnNotesTab (via leafTab('notes')) and POSTs mention payloads to /api/notes/mention. Zero note/mention UI logic reimplemented — see CaseNotesTab.vue.",
	},
	// --- The notes as a tab on the case page. ---
	//
	// The same component the sidebar's Notes tab mounts, keyed by a widget
	// TYPE for the same reason `case-task-pane` is: a child of the
	// `case-panels` tabs widget renders through CnDetailWidgetHost, which
	// picks a renderer from `cnRegistry[widget.type]` and binds `objectId`,
	// `register` and `schema` from the page, which is exactly the prop set
	// CaseNotesTab takes from the sidebar. A `type: "custom"` widget would
	// resolve through a page slot that only grid items get.
	// --- What is new on this case, and where (unread-state-on-the-case). ---
	//
	// A LAYOUT grid item rather than a tab child, and a widget TYPE rather than
	// `type: "custom"`: CnDetailPage renders a grid item through its
	// `widget-<id>` slot when the app supplies one and falls back to
	// CnDetailWidgetHost otherwise, and that host resolves a renderer from
	// `cnRegistry[widget.type]`. dossiq supplies no per-widget slots, so the
	// type is the key that has to answer.
	// @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	// --- What the status asks for (what-a-status-declares). ---
	//
	// A LAYOUT grid item and a widget TYPE, for the reason case-unread is one:
	// CnDetailPage resolves a grid item's renderer from `cnRegistry[widget.type]`
	// when the app supplies no `widget-<id>` slot, and dossiq supplies none.
	// @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	'case-status-declaration': {
		// @custom-widget-ratchet exclude the derivation verdict is not a field of the case and no declarative widget can compute one: what is missing for a derived status is evaluated per case against the status type's declared conditions, and it reaches the page on the transition engine's own answer rather than on the object. A data widget could render `waitingOn` and `currentStatusDwellDays` alone, and that would be two of the three lines with the one that matters left dark
		kind: 'widget',
		component: CaseStatusDeclarationPanel,
		_note: 'CaseDetail: what this status asks of the fields on the case, what is still missing before a status the case type derives becomes true, who the case is waiting on, and how long it has been in this status. The field rules are the one of the four that costs no round trip: OpenRegister decides them per reader and per state on the render path and publishes them as `@self.fieldRules`, so the strip reads the answer off the case object the page already holds and says them even on an instance whose transition engine refuses. The other three come from /available-transitions in one round trip, and the derivation is the one that earns the strip: a derived status is not a move a handler can pick, so an unmet derivation leaves nothing on the page to press and nothing to read. Silent on a case that asks nothing of its fields, is ours to move, is inside its maximum and has no derivation pending, and silent rather than erroring on an instance whose transition engine cannot answer.',
	},

	// --- The star on the case page (case-number-and-favourites). ---
	//
	// A LAYOUT grid item and a widget TYPE, for the reason case-unread is one:
	// CnDetailPage resolves a grid item's renderer from `cnRegistry[widget.type]`
	// when the app supplies no `widget-<id>` slot, and dossiq supplies none.
	// @spec openspec/changes/case-number-and-favourites/specs/case-management/spec.md
	'case-favourite': {
		// @custom-widget-ratchet exclude the gesture is TWO VERBS on one path, PUT to star and DELETE to unstar, and no declarative widget or action writes two methods: `CnActionButtons`' `toggle` type flips a boolean and PUTs it with one `method`, and a `handler` header action is handed `action.args` verbatim with no token resolved, so it would run with no case to act on. The state is not a field of the case either: `@self.favourite` is attached per reader on the render path, so a data widget over a property would render nothing at all. Deleted the day the library takes a two-verb toggle or a favourite affordance of its own, which is where this belongs for every index and detail page in the fleet
		kind: 'widget',
		component: CaseFavouriteStrip,
		_note: 'CaseDetail: the per-reader star, directly under the identity tiles because it is part of what identifies this case TO YOU. Starring writes nothing to the case: OpenRegister keeps the star in its own table, so no version is cut, no audit entry is written and no colleague can tell. The strip renders from `@self.favourite`, which every object read already carries, so it makes no call until somebody presses it.',
	},

	// --- The line saying this case is in the archive. ---
	//
	// A LAYOUT grid item and a widget TYPE, for the reason `case-unread` is
	// one: CnDetailPage resolves a grid item's renderer from
	// `cnRegistry[widget.type]` when the app supplies no `widget-<id>` slot,
	// and dossiq supplies none.
	// @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	'case-archived': {
		// @custom-widget-ratchet exclude the archive marker is not a property of the case: `@self.archived` is metadata OpenRegister attaches on the render path, so a `data` widget builds its fields from the schema's properties and renders nothing at all, and there is no `integration` id that reaches it. The strip also has to be SILENT on an open case, which no declarative widget can be: a widget with no per-record visibility draws its empty box on every one of the cases that are not archived. Deleted the day CnDetailPage reads `@self.archived` itself, which is where this belongs for every app in the fleet
		kind: 'widget',
		component: CaseArchivedStrip,
		_note: 'CaseDetail: the sentence that says this case is in the archive, who filed it, on what day and with what reason. It sits directly above the unread strip because it changes how everything under it should be read: the page is a record to consult rather than work to do. Restore is deliberately NOT a button here, it is one entry in the Lifecycle menu beside every other act, because an act offered in two places is gated in two places. Silent on a case that is not archived, which is almost every case.',
	},

	// @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
	'family-plan': {
		// @custom-widget-ratchet exclude the plan is three schemas read together, `gezinsplan` with its `casePlanGoal` rows and the `intervention` rows under those, with `overdue` and `dueForReview` computed on the server against today. No declarative widget joins three schemas, and a `data` widget over `gezinsplan` would render the plan's own fields and none of its goals. Deleted the day a widget type can render a two-level child collection with a server-computed flag per row
		kind: 'widget',
		component: CasePlanSociaalDomeinPanel,
		_note: 'CaseDetail, Jeugdwet: the family plan, its goals and the interventions under them. NOT the adaptive case plan beside it: CasePlanPanel renders OpenRegister\'s case layer, the stages and milestones of any case, and this is what this household agreed to work on, who is doing what about it and by when. Two things called a plan. Every line carries something a review can decide about: a goal says what would count as met, an intervention says who carries it out and by when, so it can be late. The register held both as plain strings until the-social-domain-plan-and-its-grounds, which is why this panel exists at all. It derives no verdict: overdue and dueForReview come from the server, so the panel and the plan endpoint cannot disagree about which household is being worked to a stale plan. Interventions that name no goal are shown separately rather than hidden, because activity nobody can connect to a goal is what a reviewer should be looking at.',
	},

	'case-unread': {
		// @custom-widget-ratchet exclude the per-user read state is not a field of the case and no declarative widget reads it: `@self.unreadCounts` is attached on the render path, the count per panel comes from OpenRegister's read-state endpoint, and the gesture that clears one is a PUT carrying a sub-resource. Deleted the day CnTabsWidget takes a badge per tab and emits its tab change, which is where this belongs (nextcloud-vue, clusters 58 and 15)
		kind: 'widget',
		component: CaseUnreadPanel,
		_note: 'CaseDetail: what changed on this case since the handler last looked, named per panel so they know where to look rather than only that something moved. Opening the case marks the case read and empties the notifications that were about it, in one write; it deliberately does not stamp the panels, so a document that arrived is still counted until the documents are looked at. Silent on a case with nothing new, and silent rather than erroring on an instance whose OpenRegister does not carry the read state yet.',
	},

	// --- Following a case you do not own (case-followers, row 13.18). ---
	//
	// A LAYOUT grid item and a widget TYPE for the strip, and a TAB CHILD type
	// for the panel. Both resolve from `cnRegistry[widget.type]`: CnDetailPage
	// falls back to CnDetailWidgetHost for a grid item with no `widget-<id>`
	// slot, and CnTabsWidget resolves a panel the same way and renders nothing
	// at all, logging nothing, when no key answers.
	// @spec openspec/changes/case-followers/specs/case-management/spec.md
	'case-follow': {
		// @custom-widget-ratchet exclude the gesture is TWO VERBS on one path, PUT to follow and DELETE to stop, and no declarative action writes both: `executeApiCall` maps every method that is not `PUT` to `post`, so an `api-call` declared `method: "DELETE"` would POST to a route that takes DELETE and there is nothing in the manifest to say it could never have worked. `CnActionButtons`' `toggle` type writes with one `method` for both directions, and a `handler` header action is handed `action.args` verbatim with no token resolved, so it would run with no case to act on. The state is not a field of the case either: `@self.watching` is attached per reader on the render path, so a data widget over a property would render nothing. Deleted the day the library takes a DELETE verb and a two-verb toggle, which is where this belongs for every app in the fleet
		kind: 'widget',
		component: CaseFollowStrip,
		_note: 'CaseDetail: follow a case you do not own, beside the star and saying the opposite kind of thing. The star is private and silent; following subscribes you to the case\'s own notifications through OpenRegister\'s `{"watchers": true}` recipient block, and the people who may edit the case can see that you took it. The strip renders from `@self.watching`, which every object read already carries, so it makes no call until somebody presses it. The count beside the button is silent for a reader OpenRegister told no count, because an absent `@self.watcherCount` means "not your business" and never "nobody".',
	},

	'case-followers': {
		// @custom-widget-ratchet exclude a subscription is not an OpenRegister OBJECT and every built-in list widget takes a register and a schema: the rows come from `/api/objects/{r}/{s}/{id}/watchers`, a sub-resource that takes no register-and-schema pair of its own, and there is no `integration` id that reaches it. The 403 a reader without `update` gets has to be drawn APART from an empty list, which a declarative list cannot do: both would render as no rows. Deleted the day nextcloud-vue ships a watchers widget type over that listing
		kind: 'widget',
		component: CaseFollowersPanel,
		_note: 'CaseDetail People tab, the Followers section: who is watching this case, as against the Parties, Roles and Seats sections beside it, which say who the case is about. Reading the list needs `update` on the case, so a reader without it is told that rather than shown an empty list, which would be a claim about the audience nobody made to them. No remove button: taking off somebody else\'s subscription needs `manage`, and you stop following from the button on the case page, which acts on your own row only.',
	},

	// --- Who is on the case, and in which role (the party model, #3761). ---
	// Keyed by the widget's `type` and not by a component name, for the reason
	// `case-unread` records: a tab child renders through CnTabsWidget, which
	// resolves `cnRegistry[widget.type]` and renders nothing at all when no key
	// answers.
	// @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
	'case-party-roles': {
		// @custom-widget-ratchet exclude a party link is not an OpenRegister OBJECT and every built-in list widget takes a register and a schema: the rows come from `/api/objects/{r}/{s}/{id}/parties`, which answers contact-link rows grouped by role together with the schema's own kinds and roles, and the indicators come from `/api/parties/{uuid}`. There is no `integration` id that resolves the party model either; `contacts` renders the person links beside this and cannot see a party with no account. Deleted the day nextcloud-vue ships a parties widget type over that listing
		kind: 'widget',
		component: CasePartiesWidget,
		_note: 'CaseDetail People tab, the Roles section: the parties of the case grouped by role with the primary party first, which on a case is the initiator. It is the half the contacts integration beside it cannot carry -- a melder with no Nextcloud account, a gemachtigde acting for the applicant, and the indicators a party holds. An indicator renders WITH its verdict (warn, refuse publication, refuse send) because an indicator that only renders is one somebody misses; the two refusals are enforced again where the act happens, in BesluitPublicatiePanel and FileRequestService, and once more inside OpenRegister. A failed read says so in words rather than drawing an empty party list, which would read as a case whose parties had been removed.',
	},

	'case-timeline-pane': {
		// @custom-widget-ratchet exclude the surface is OpenRegister's TIMELINE, not a collection of OpenRegister objects: the entries come from /api/objects/{register}/{schema}/{id}/timeline, which takes no register-and-schema pair of its own, and a built-in object-list takes exactly that. There is no `integration` id for the timeline either, so `type: "integration"` cannot reach it. The pin and the follow-up are PATCHes on a sub-resource, which no declarative widget writes. This entry is deleted the day the library ships a timeline widget type
		kind: 'widget',
		component: CaseTimelineTab,
		_note: "CaseDetail Timeline tab: one chronological read of every note, logged call, message and acknowledgement on this case, from OpenRegister's timeline. Notes, Communication and Email stay beside it because each is the place to DO that one thing; this is the place to see the order. The audit sidebar keeps the change history.",
	},

	'case-attention': {
		// @custom-widget-ratchet exclude the three facts on this strip cannot be read by a declarative widget: the flag is written through an endpoint that refuses a reasonless act and appends rather than overwriting, the marker set is an array of derived rows each pointing at a panel of THIS page, and the risk assessment is a property OpenRegister filters out entirely for a reader without the extra group, so a field widget would render an empty box that looks like an absent assessment
		kind: 'widget',
		component: CaseAttentionPanel,
		_note: 'CaseDetail: the flag a person raised with a written reason, the risk this organisation assessed and the markers the system raised against a named panel. Three different facts kept apart on purpose. Sits under the unread strip and says the opposite kind of thing: a marker survives opening the panel it points at and goes when the work behind it is done, where the unread badge goes because somebody looked.',
	},

	'case-notes-pane': {
		kind: 'widget',
		component: CaseNotesTab,
		_note: 'CaseDetail Notes tab: the mention-aware CnNotesTab through CaseNotesTab, the same surface the sidebar offers, so a handler reading the case file does not have to open the sidebar to leave a note on it.',
	},
	// --- Sharing/transfer sidebar tab (federated-case-collaboration). ---
	// Wires the previously-orphaned ShareTab/CreateShareDialog/
	// CaseTransferDialog components (zero references anywhere before this
	// change) plus the new federated-share/activity UI into the real
	// case-detail sidebar. See CaseSharingTab.vue + design.md §7.
	// @spec openspec/specs/federated-case-collaboration/spec.md#the-case-detail-sharing-surface-is-wired-not-orphaned
	// --- Access sidebar tab (case-grants-name-their-source). ---
	// Who holds which right on this case, and where each grant came from,
	// read from OpenRegister and computed nowhere. A `component:` sidebar tab
	// rather than a built-in: a sidebar tab renders EITHER a registered
	// component or a `widgets[]` entry whose type is one of CnObjectSidebar's
	// four built-ins (data, metadata, audit, object-table), and none of them
	// can read `/api/permissions`, `/api/scopes` or `/api/permissions/
	// deny-preview`. A `type: "custom"` entry here resolves to nothing and
	// renders an empty panel with a console warning nobody reads.
	// @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	CaseAccessTab: {
		kind: 'page',
		component: CaseAccessTab,
		_note: "Who holds which right on the case and where each grant came from, read from OpenRegister's permission catalogue, object shares, role definitions, effective scopes and deny preview. dossiq evaluates nothing: every row restates one rule OpenRegister reported, and a deny is its own row rather than subtracted from a grant, because a second evaluator of this question eventually disagrees with the first and the disagreement is a disclosure (D-1, D-5).",
	},
	// --- The AVG panel (data-subject-requests-drive-the-platform). ---
	// A `component:` tab for the same reason CaseAccessTab is: none of
	// CnObjectSidebar's four built-ins can call the AVG endpoints or render a
	// protected item with its ground. kind `page`, so it adds nothing to the
	// ADR-049 widget count.
	// @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	DataSubjectRequestTab: {
		kind: 'page',
		component: DataSubjectRequestTab,
		_note: 'What OpenRegister reported about a data subject, and the acts dossiq drives on it. The protected items are rendered in full, ground and basis and remedy, because a count of what cannot be erased is not an answer a handler can give the person who asked. dossiq computes no erasure here: every value is one the server read back from the platform.',
	},
	// --- The four clocks on the case (phase-terms-and-the-internal-target). ---
	// A `component:` tab and not a `widgets[]` one, for the same reason
	// CaseAccessTab is: a sidebar tab renders either a registered component or
	// one of CnObjectSidebar's built-ins (data, metadata, audit, object-table),
	// and none of the four can read /api/cases/{id}/terms. It is kind `page`,
	// which is what a sidebar-tab component is in this registry; it is NOT a
	// custom `widget`, so it adds nothing to the ADR-049 widget count.
	// @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	CaseTermsTab: {
		kind: 'page',
		component: CaseTermsTab,
		_note: "The statutory term, the planned end, the internal target and the phase term, each apart and each saying what it is, with the progress and the days left beside them. Every number is the server's: the browser computes no percentage, so the case page and the list column read one computation and cannot disagree. The internal target is drawn here and refused to every citizen surface by the server, which answers /terms/citizen with the statutory term alone.",
	},
	CaseSharingTab: {
		kind: 'page',
		component: CaseSharingTab,
		_note: 'Partner + federated case sharing, transfer and activity — container that wires ShareTab/CreateShareDialog/CaseTransferDialog/CreateFederatedShareDialog/FederatedActivityPanel to the backend API.',
	},
	AdviesPanel: {
		kind: 'page',
		component: AdviesPanel,
		_note: 'Advice/advies panel used in CaseDetail and BezwaarDetail sidebar tabs',
	},

	// --- Besluitvorming (decision-making) sidebar tab — decidesk leaf. ---
	// "decidesk owns it; dossiq shows a leaf" (ADR-019 / ADR-022). The
	// decidesk `decidesk-decisions` integration leaf (registered cross-app on
	// the shared OR integration registry by decidesk's global init script)
	// surfaces proposals/advice/decisions linked to this case via decidesk's
	// subjectId back-reference. The wrapper resolves the registered provider's
	// tab at render time and forwards the case `{ register, schema, objectId }`
	// context that CnObjectSidebar injects. Retires dossiq's former standalone
	// Voorstellen/Advies/Agenda nav.
	// @spec openspec/changes/consume-decidesk-besluitvorming-leaf/tasks.md
	BesluitvormingLeafTab: {
		kind: 'page',
		component: BesluitvormingLeafTab,
		_note: 'decidesk decisions integration leaf (decidesk-decisions) surfaced on the case detail; replaces the standalone Besluitvorming nav (ADR-019/ADR-022).',
	},

	// --- VTH module: case detail sidebar tabs. ---
	// @spec openspec/changes/vth-module/tasks.md#task-7
	AdviceRequestPanel: {
		kind: 'page',
		component: AdviceRequestPanel,
		_note: 'VTH advice request panel — shows open/received/overdue adviesAanvragen on VTH case detail',
	},
	InspectionChecklistPanel: {
		kind: 'page',
		component: InspectionChecklistPanel,
		_note: 'VTH checklist panel — shows inspection checklist completion status on Toezichtzaak',
	},
	InspectionPanel: {
		kind: 'page',
		component: InspectionPanel,
		_note: 'VTH inspection panel — shows completed inspectionResult records for a case',
	},

	// --- Forms + Photos leaves — leaf-first per ADR-022. ---
	// Inspection checklist / advice forms render through OR's `forms` leaf
	// (FormsProvider / CnFormsTab), inspection photos through OR's `photos`
	// leaf (PhotosProvider / CnPhotosTab). Both are resolved from the lib's
	// builtinIntegrations registry and fetch straight from OpenRegister using
	// the objectId/register/schema/apiBase CnObjectSidebar injects. The
	// checklist photo-gate + append-only immutability stay in-app (domain rules).
	// @spec openspec/changes/migrate-inspection-forms-to-forms-leaf/tasks.md#P1.2
	// @spec openspec/changes/migrate-inspection-forms-to-forms-leaf/tasks.md#P1.3
	FormsLeafTab: {
		kind: 'page',
		component: leafTab('forms'),
		_note: 'OR forms integration leaf (CnFormsTab) — renders checklist/advice forms on the case detail; replaces the bespoke hand-rendered checklist inputs (ADR-022).',
	},
	PhotosLeafTab: {
		kind: 'page',
		component: leafTab('photos'),
		_note: 'OR photos integration leaf (CnPhotosTab) — stores/shows inspection photos as files attached to the object; replaces inline photos[] payloads (ADR-022).',
	},

	// --- Maps leaf — leaf-first per ADR-022 (per-case map surface). ---
	// The case's location is rendered by OR's `maps` integration leaf
	// (MapsProvider / CnMapsTab): the leaf owns tiles, layers, zoom and
	// marker interaction and fetches straight from OpenRegister using the
	// objectId/register/schema/apiBase CnObjectSidebar injects. Replaces the
	// bespoke per-case Leaflet surface (LocationTab → CaseMap). The
	// multi-object cases-on-map overview (CasesOnMapView / /map page) is OUT
	// OF SCOPE here — tracked as a separate OR maps-overview follow-up.
	// @spec openspec/changes/migrate-maps-to-maps-leaf/tasks.md#P1.2
	MapsLeafTab: {
		kind: 'page',
		component: leafTab('maps'),
		_note: 'OR maps integration leaf (CnMapsTab) — renders the case location marker on the case detail; replaces the bespoke per-case LocationTab/CaseMap (ADR-022).',
	},

	// --- Zaakportaal "Mijn gemeente" citizen portal MOVED to Portaliq
	//     (ADR-046, procest#162): re-expressed as the `citizen` audience in
	//     lib/Portal/PortalContributionProvider.php. See import-section comment. ---

	// --- Dashboard signal widgets + charts + header actions DISSOLVED (ADR-049). ---
	// casesOverview / overdueCases / stalledCases / myTasks / taskReminders /
	// deadlineAlerts are now built-in `object-table` widgets, statusChart /
	// casesByType are built-in `chart` widgets, and DashboardHeaderActions is
	// now a declarative `config.headerActions[]` array — all declared inline on
	// the manifest Dashboard page (src/manifest.json) and resolved by the
	// library, so they no longer need an app-registry entry. The self-fetching
	// `src/views/widgets/*.vue` components stay for the native NC Dashboard
	// (registered via the `src/*Widget.js` OCA.Dashboard entry points).

	// --- Leverancier-zaakportaal external supplier portal MOVED to Portaliq
	//     (ADR-046, procest#162) — see import-section comment. ---
}

export default registry
