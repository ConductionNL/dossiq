// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// V2 component registry for dossiq.
//
// THE ONLY registry this app passes to CnAppRoot. The legacy string-keyed
// `customComponents` prop is deprecated for a v2 manifest and is gone: every
// page, dashboard slot and handler it carried is a kinded entry here.
//
// Every entry corresponds to something a manifest names by string — a
// `type: "custom"` page, a sidebar tab using `component:` instead of
// `widgets[]`, a page `slots` entry, or an `actions[]` / `bulkActions[]` /
// `headerActions[]` handler. The key is that string.
//
// Recognised kinds: page, modal, widget, actions, handler, form-field,
// cell-renderer. A `handler` entry exposes a plain function as `.handler` and
// is resolved by name, never mounted.
//
// Migration notes:
// - The visual workflow editor (`WorkflowEditor.vue`) is not a registry entry
//   — it is a plain child component mounted by `WorkflowTab.vue` inside the
//   case-type detail page's "Workflow" tab, not a manifest `type:"custom"`
//   page or sidebar-tab component. See openspec/specs/visual-workflow-editor.
//   A second, @vue-flow-based implementation (Vue-3-only, incompatible with
//   this app's Vue 2.7 build) was removed by workflow-editor-integration.

// The AI features a case type switched on, and the report group the case is in
// (ai-features-on-the-case-consume-hermiq, board DqZaakAssistent).
import CaseAiFeaturesPanel from './components/case/CaseAiFeaturesPanel.vue'
// The star on the case page (case-number-and-favourites, row 2.19).
// @spec openspec/changes/case-number-and-favourites/specs/case-management/spec.md
// Who has held this case, and who is asking for it
// (custody-and-handover-of-a-case, rows 2.37 and 2.38).
// @spec openspec/specs/case-management/spec.md
import CaseCustodyPanel from './components/case/CaseCustodyPanel.vue'
import DocumentReviewPanel from './components/case/DocumentReviewPanel.vue'
// A reviewer's own pending archival decisions, on My Work.
// @spec openspec/specs/archief-edepot-handover/spec.md
// The case type's effective blueprint: what it offers, and what it inherited.
// @spec openspec/specs/case-types/spec.md
import CaseTypeBlueprintWidget from './components/caseType/CaseTypeBlueprintWidget.vue'
import CaseTypeFieldRightsWidget from './components/caseType/CaseTypeFieldRightsWidget.vue'
import CaseTypeMessagesEditorWidget from './components/caseType/CaseTypeMessagesEditorWidget.vue'
// The party kinds a case type accepts, declared into pipelinq
// (parties-and-contact-moments-consume-pipelinq 3.2, board DqZaaktype).
import CaseTypePartyKindsWidget from './components/caseType/CaseTypePartyKindsWidget.vue'
import CaseTypePortalWidget from './components/caseType/CaseTypePortalWidget.vue'
import CaseTypeRulesWidget from './components/caseType/CaseTypeRulesWidget.vue'
// A case type's labels in every language the register serves
// (case-type-labels-are-translatable, row 11.13).
// @spec openspec/specs/case-configuration-i18n/spec.md
import CaseTypeTranslationsWidget from './components/caseType/CaseTypeTranslationsWidget.vue'
import CaseTypeWorkflowEditorWidget from './components/caseType/CaseTypeWorkflowEditorWidget.vue'
// The task page's leaves, as detail widgets (task-page-on-the-manifest).
// Initiator (indiener) selection + display — brp-kvk-register-sets.
// @spec openspec/specs/initiator-selection/spec.md
import RequesterProjection from './components/initiator/RequesterProjection.vue'
// Ask whether this case already exists, before it does.
// @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
// A search openregister refused, said where the term was typed.
// @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
// "Besluitvorming" decision-making is owned by decidesk and surfaced here as
// an OR integration leaf (decidesk-decisions) on the case-detail sidebar.
// @spec openspec/changes/consume-decidesk-besluitvorming-leaf/tasks.md
// Detail-tab components (used as `component:` in sidebarTabs[])
// Send digital post — the CaseDetail header action's compose surface.
// @spec openspec/specs/berichtenbox-integration/spec.md
import AssessmentDecisionDialog from './dialogs/AssessmentDecisionDialog.vue'
import BerichtenboxComposeDialog from './dialogs/BerichtenboxComposeDialog.vue'
// Generate document — the CaseDetail header action's template picker.
// @spec openspec/specs/beschikking-generatie/spec.md
import BeschikkingComposerDialog from './dialogs/BeschikkingComposerDialog.vue'
// The Actions menu's non-lifecycle gestures: copy this case, start a flow
// its type allows, and plan a follow-up case for a later date.
// @spec openspec/specs/case-management/spec.md
import CaseCopyDialog from './dialogs/CaseCopyDialog.vue'
import CaseLifecycleMenuDialog from './dialogs/CaseLifecycleMenuDialog.vue'
// The case as OpenRegister stored it, behind the admin-only Inspect action.
// @spec openspec/changes/admin-inspect-entry/specs/case-management/spec.md
import CaseLocationDialog from './dialogs/CaseLocationDialog.vue'
import CaseMergeDialog from './dialogs/CaseMergeDialog.vue'
import CasePlanFollowUpDialog from './dialogs/CasePlanFollowUpDialog.vue'
import CasePublishDialog from './dialogs/CasePublishDialog.vue'
import CaseRawDataDialog from './dialogs/CaseRawDataDialog.vue'
import CaseRebindDialog from './dialogs/CaseRebindDialog.vue'
import CaseShareOrganisationDialog from './dialogs/CaseShareOrganisationDialog.vue'
import CaseSplitDialog from './dialogs/CaseSplitDialog.vue'
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
import DocumentAccessExceptionDialog from './dialogs/DocumentAccessExceptionDialog.vue'
import DocumentAssessDialog from './dialogs/DocumentAssessDialog.vue'
import DocumentCompareDialog from './dialogs/DocumentCompareDialog.vue'
import FileContactMomentDialog from './dialogs/FileContactMomentDialog.vue'
import GatherDocumentsDialog from './dialogs/GatherDocumentsDialog.vue'
import LinkProgrammeDialog from './dialogs/LinkProgrammeDialog.vue'
import LogContactDialog from './dialogs/LogContactDialog.vue'
import NoticeOfDefaultDialog from './dialogs/NoticeOfDefaultDialog.vue'
import PortalMessageDialog from './dialogs/PortalMessageDialog.vue'
// Remind a colleague about this case on a date (case-reminder-as-task).
// @spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md
import RemedyLodgeDialog from './dialogs/RemedyLodgeDialog.vue'
import RemindDialog from './dialogs/RemindDialog.vue'
import WooSearchPlanDialog from './dialogs/WooSearchPlanDialog.vue'
import BulkDocumentActionDialog from './modals/BulkDocumentActionDialog.vue'
import DeelzaakCreateModal from './modals/DeelzaakCreateModal.vue'
// The Documents tab's upload dialog and bulk-action dialog
// (documents-on-the-case task 2.2: the tab itself is now a `type:
// "object-list"` CnObjectListWidget, resolved by the library, not a
// registry widget entry — only the two modals it dispatches to are ours).
// @spec openspec/specs/document-zaakdossier/spec.md
// @spec openspec/specs/document-zaakdossier/spec.md
import DocumentMetadataDialog from './modals/DocumentMetadataDialog.vue'
import DossierExportPanel from './modals/DossierExportPanel.vue'
import FileRequestDialog from './modals/FileRequestDialog.vue'
import VersionHistoryPanel from './modals/VersionHistoryPanel.vue'
// VTH-specific case detail panels
// Federated case sharing/transfer/activity — federated-case-collaboration.
// @spec openspec/specs/federated-case-collaboration/spec.md
import CaseAccessTab from './views/cases/components/CaseAccessTab.vue'
// Case-assistant chat panel — conversational assistance delegated to Hermiq
// (fleet rule: AI functionality lives in Hermiq; dossiq is a thin consumer).
// @spec openspec/specs/case-assistant-via-hermiq/spec.md
import CaseConversationsPanel from './views/cases/components/CaseConversationsPanel.vue'
import CaseSharingTab from './views/cases/components/CaseSharingTab.vue'
import CaseTermsTab from './views/cases/components/CaseTermsTab.vue'
// The AVG panel on a data subject request case
// (data-subject-requests-drive-the-platform).
import DataSubjectRequestTab from './views/cases/components/DataSubjectRequestTab.vue'
// --- Pages and dashboard slots that were only ever in the legacy
//     customComponents map, plus the handlers a manifest names by string. ---
import MailIntakeLogView from './views/intake/MailIntakeLogView.vue'
import PublicAppointmentPage from './views/public/PublicAppointmentPage.vue'
import PublicFederatedTransferPage from './views/public/PublicFederatedTransferPage.vue'
import PublicStatusPage from './views/public/PublicStatusPage.vue'
import MailPartiesReportWidget from './views/reports/MailPartiesReportWidget.vue'
import CaseTypeFlowView from './views/settings/CaseTypeFlowView.vue'
import WorkloadTeamChips from './views/WorkloadTeamChips.vue'
import WorkloadTeamHeading from './views/WorkloadTeamHeading.vue'
import { leafTab } from './integrations/leafTabs.js'
import { createCaseWithDuplicateCheck } from './services/createCaseWithDuplicateCheck.js'
import {
	extendTermSelection,
	reassignSelection,
	resumeSelection,
	suspendSelection,
	transitionSelection,
} from './utils/caseBulkActions.js'
import { claimCase } from './utils/caseClaim.js'
import { declareCaseMajor, startCaseConversation } from './utils/caseConversation.js'
import { toggleCaseFollow } from './utils/caseFollow.js'
import { caseStatusTargets, moveCaseStatus } from './utils/caseStatusBoardMoves.js'
import { markCaseRead, markCaseUnread } from './utils/caseUnread.js'
import { openCaseOfDocument } from './utils/contactDocuments.js'
import { openIntegriqConnections } from './utils/integriqConnections.js'
import { prepareNoticePayout } from './utils/noticePayout.js'
import { readFileAsMessage } from './utils/savedMail.js'

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

/*
 * Grid metadata required for every kind:"widget" entry by the ADR-036
 * registry validator in CnAppRoot. dossiq positions every one of these
 * through the manifest itself — a `layout` grid item, a tabs-widget child
 * resolved by TYPE, or a headless actionsComponent — never through registry
 * metadata, so these sizes are not consumed at runtime; they exist to
 * satisfy the validator. `allowedSlots` uses the v2 slot enum (body,
 * sidebar, header-actions, footer, modal); every entry below renders in the
 * page body.
 */
const PANEL_WIDGET_META = {
	defaultSize: { w: 12, h: 6 },
	minSize: { w: 6, h: 3 },
	maxSize: { w: 12, h: 12 },
	allowedSlots: ['body'],
	propsSchema: null,
}
const DASHBOARD_TILE_META = {
	defaultSize: { w: 6, h: 4 },
	minSize: { w: 3, h: 2 },
	maxSize: { w: 12, h: 8 },
	allowedSlots: ['body'],
	propsSchema: null,
}
// RequesterProjection mounts headless (see its entry below) and is never
// placed as a grid item, so its size is zero rather than a guessed number.
const HEADLESS_WIDGET_META = {
	defaultSize: { w: 0, h: 0 },
	minSize: { w: 0, h: 0 },
	maxSize: { w: 0, h: 0 },
	allowedSlots: ['body'],
	propsSchema: null,
}

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
	// --- Genuine exceptions: no abstract manifest analogue. ---
	// The Assigned to me view's slots (Cases `defaultSavedViews` my-work
	// `overrides.slots`): the list itself is manifest config.
	WorkloadTeamHeading: {
		kind: 'page',
		component: WorkloadTeamHeading,
		_note: "Below-header slot: the team workload's title and hint, drawn only for a coordinator (/api/work-queue/workload answers 403, and so nothing, for anyone else).",
	},
	WorkloadTeamChips: {
		kind: 'page',
		component: WorkloadTeamChips,
		_note: "Toolbar-start slot: the coordinator's open cases per colleague as name chips, at the start of the toolbar row (board DqAanMijToegewezen). A colleague's chip opens their open cases on the Cases page.",
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
		// A titled card in the Statuses tab stack, as the lists above it.
		chrome: 'card',
		component: CaseTypeBlueprintWidget,
		...PANEL_WIDGET_META,
		_note: 'CaseTypeDetail: what the type actually offers, over /api/case-types/{id}/blueprint, with an Inherited badge on every row that came from the parent and a Shared badge on every attribute that belongs to no type. In the LAYOUT rather than inside a tab strip on purpose: a type:"custom" widget named as a tab CHILD resolves by registry type, finds nothing and renders an empty panel without logging anything.',
	},
	// --- A case type's labels in every language (case-type-labels-are-translatable). ---
	// @spec openspec/specs/case-configuration-i18n/spec.md
	CaseTypeTranslationsWidget: {
		// @custom-widget-ratchet exclude the library has no translatable-property surface at all: @conduction/nextcloud-vue 3.4.0 contains no reference to translatable, languageMeta or sourceLanguage, so no declared widget can render a language tab or a completeness chip
		kind: 'widget',
		// A titled card on the Overview tab (DqZaaktypeOverzicht Vertalingen); the Translations tab draws it bare.
		chrome: 'card',
		component: CaseTypeTranslationsWidget,
		...PANEL_WIDGET_META,
		_note: "CaseTypeDetail: one chip per language the register declares, and an editor per language for the labels OpenRegister holds as translatable. It counts a stale label as missing, which OpenRegister's own completeness does not: getCompletenessByObject() counts every non-empty row and never reads its status. It writes the WHOLE language map and sends no X-Translation-Target-Language: normalizeTranslationsForSave() refuses a language keyed body that arrives with that header and keeps one that arrives without it.",
	},
	// --- What a case type opens to its applicant (portal-citizen-writes-on-the-case D3). ---
	// @spec openspec/specs/portal-contribution/spec.md
	CaseTypePortalWidget: {
		// @custom-widget-ratchet exclude every picker offers the case type's own statuses, inherited ones included, which only /api/case-types/{id}/blueprint answers; a declared form would offer a free text field for a status uuid
		kind: 'widget',
		component: CaseTypePortalWidget,
		...PANEL_WIDGET_META,
		_note: 'CaseTypeDetail: the Portal section. Reads the case type and its blueprint statuses, writes portalWritable, both windows and portalWithdrawal in one PATCH, and shows the sentence CaseTypePortalWithdrawalListener refuses an unreachable withdrawal with.',
	},
	// --- The editors of the retired admin-settings case type editor, rehosted
	// on CaseTypeDetail while their forms have no declarative equivalent. ---
	// @spec openspec/specs/case-types/spec.md
	CaseTypeWorkflowEditorWidget: {
		// @custom-widget-ratchet exclude a visual canvas of statuses, transitions, steps and guards with a version selector and publish; no declared widget draws or edits a graph
		kind: 'widget',
		component: CaseTypeWorkflowEditorWidget,
		...PANEL_WIDGET_META,
		_note: 'CaseTypeDetail Phases and tasks: the workflow canvas, kept by decision when the admin-settings editor was retired.',
	},
	// @spec openspec/specs/case-types/spec.md
	CaseTypeMessagesEditorWidget: {
		// @custom-widget-ratchet exclude a message is edited with the case variables to insert and a live preview that flags an unresolved variable; no declared form offers either
		kind: 'widget',
		component: CaseTypeMessagesEditorWidget,
		...PANEL_WIDGET_META,
		_note: 'CaseTypeDetail Phases and tasks: the messages to the applicant through the email template endpoints, so each change is a new version (EmailTemplateVersionListener keeps that on the object API too).',
	},
	// @spec openspec/specs/case-types/spec.md
	CaseTypeFieldRightsWidget: {
		// @custom-widget-ratchet exclude the matrix has one column per status of the case type, read at run time, and its cells combine three stored declarations; a declared table has fixed columns
		kind: 'widget',
		chrome: 'card',
		component: CaseTypeFieldRightsWidget,
		...PANEL_WIDGET_META,
		_note: 'CaseTypeDetail Fields: the field rights as a matrix of fields by status (DqZaaktypeVelden), read from fieldRoleRules, the statuses\' required field rules and requiredAtStatus.',
	},
	// @spec openspec/specs/case-types/spec.md
	CaseTypeRulesWidget: {
		// @custom-widget-ratchet exclude the rules are read from OpenRegister's rules vocabulary and evaluated against a pasted case; no declared widget evaluates a rule
		kind: 'widget',
		// A titled card in the Rules tab, as board DqZaaktypeRegels draws it.
		chrome: 'card',
		component: CaseTypeRulesWidget,
		...PANEL_WIDGET_META,
		_note: 'CaseTypeDetail Fields: the OpenRegister rules of the case schema with a try-it. They belong to the case schema rather than to one case type, so this is a candidate to move to OpenRegister\'s schema page.',
	},
	// @spec openspec/specs/zaaktype-versioning/spec.md
	CaseTypePublishDialog: {
		kind: 'modal',
		component: CaseTypePublishDialog,
		propsSchema: {},
		_note: 'CaseTypeDetail Publish: reads /publish/validate FIRST and shows the findings instead of a note field when there are any, so a person about to be refused is told before being made to write a note they would lose. A declarative api-call cannot express that order.',
	},
	// @spec openspec/specs/workflow-import-export/spec.md
	CaseTypeImportDialog: {
		kind: 'modal',
		component: CaseTypeImportDialog,
		propsSchema: {},
		_note: "CaseTypeDetail Import: the import takes a FILE, and neither a header action's confirm gate (a plain dialog with no fields) nor an api-call (a JSON body) can carry a multipart upload. Declared declaratively it would be a button that cannot do the one thing it is for.",
	},
	// @spec openspec/specs/workflow-import-export/spec.md
	CaseTypeDuplicateDialog: {
		kind: 'modal',
		component: CaseTypeDuplicateDialog,
		propsSchema: {},
		_note: 'CaseTypeDetail Duplicate: posts the copy, reads the new id out of the answer and ROUTES there. An api-call refreshes the page you are already on, so a person who asked for a copy would be left looking at the original with no clue where the copy went.',
	},
	// @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	CaseTypeNewVersionDialog: {
		kind: 'modal',
		component: CaseTypeNewVersionDialog,
		propsSchema: {},
		_note: 'CaseTypeDetail New version: posts the next version and ROUTES to the draft, for the reason Duplicate is a dialog. The tasks called for a declarative api-call, and an api-call refreshes the page you are already on, so the person who asked for a new version would be left on the old one with the draft nowhere in sight. It also says what a version IS before making one: the gesture beside it is Duplicate, and a duplicate is a second case type while a version is this one later on.',
	},
	// @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	CaseVersionMoveDialog: {
		kind: 'modal',
		component: CaseVersionMoveDialog,
		propsSchema: {},
		_note: 'CaseDetail Actions menu: move this case to another version of its own case type. The PREVIEW is why it is a modal and not a confirm gate: a case is pinned to the version it was filed under because its status is a row only that version holds, so the person moving it is shown the landing status and the statuses and fields the other version adds and drops, including the dropped ones this case has answered. It derives NONE of that: canMove and every refusal sentence come from the server, so the dialog cannot disagree with the write.',
	},
	// @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
	CrossDomainLookupDialog: {
		kind: 'modal',
		component: CrossDomainLookupDialog,
		propsSchema: {},
		_note: 'Is this household already known to another domain. A modal and not an api-call because the GROUND is the act: it is chosen before the answer rather than filled in afterwards, which is what makes the lookup deliberate and what makes the log mean something when the person asks what was looked up about them. The answer is three facts, that an open case exists, in which domain and who to call, and there is no show-more and never will be: purpose limitation between Wmo, Jeugdwet and Participatiewet does not allow the 360 view that gap row 5.4 asks for. The grounds come from the server, because a second copy here would drift and a ground on screen the service does not know is a choice a consulent makes and is then refused for.',
	},
	// @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	CaseRebindDialog: {
		kind: 'modal',
		component: CaseRebindDialog,
		propsSchema: {},
		_note: 'CaseDetail Actions menu: move this case to a DIFFERENT case type, which the version move beside it cannot do. It is a modal and not a confirm gate because the MAPPING is the act: across two case types a status name means nothing, so the landing status is asked for rather than derived, and so is every property the target requires in that status and the case does not carry. The dialog derives no verdict: canRebind, the missing names and every refusal come from the server, and the group check lives in the service rather than in this button.',
	},
	// @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
	// @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02
	// @spec openspec/specs/deelzaak-support/spec.md#requirement-sub-case-creation-from-parent-case
	DeelzaakCreateModal: {
		kind: 'modal',
		component: DeelzaakCreateModal,
		propsSchema: {},
		_note: "CaseDetail `case-sub-cases` list's create action. Creates the sub-case through /api/deelzaken (validate, then derive), so it inherits what case.parentCase declares; the list's own create form would save the case directly and inherit nothing. parentCase arrives as the resolved `@objectId`; the modal reads the parent and its case type itself and says why when the parent cannot take a sub-case.",
	},

	// @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
	NoticeOfDefaultDialog: {
		kind: 'modal',
		component: NoticeOfDefaultDialog,
		propsSchema: {},
		_note: "CaseDetail `notice-of-default-register` header action, boards DqZaak and DqTermijnen. Posts to /api/notices-of-default with the case id, so the server picks the case's statutory decision term, checks the caller may change the case, and refuses a notice while the term runs (409, nothing stored). A form over the noticeOfDefault schema would store a premature notice and start no accrual.",
	},

	LogContactDialog: {
		kind: 'modal',
		component: LogContactDialog,
		propsSchema: {},
		_note: "CaseDetail `log-contact` header action, board DqZaakContactmomenten. Replaces the open-form that saved straight to OpenRegister's object API: that path ran no dossiq service, so the moment never reached pipelinq's customer record and a refusal could not be shown. This posts to PipelinqCaseController#logContactMoment, where ContactMomentService writes the dossiq record first and appends it to pipelinq, and the answer carries pipelinq's refusal (with the indicator) back. A refusal keeps the dialog open on a summary that says both: the moment is on the case, and pipelinq did not take it. caseId arrives as the unresolved `@objectId` token, so the route answers.",
	},

	// @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
	CaseTypePartyKindsWidget: {
		// @custom-widget-ratchet exclude the vocabulary is pipelinq's and the write is a declaration into pipelinq's register (`dossiq:case:<type>`, patched, never replaced), which only PipelinqCaseController reaches; a declared form over a dossiq schema has nowhere to write it
		kind: 'widget',
		component: CaseTypePartyKindsWidget,
		...PANEL_WIDGET_META,
		_note: "CaseTypeDetail: the Partijsoorten section. Lists pipelinq's kinds with the accepted ones first in declared order, moves them up and down, and saves the declaration to pipelinq. Without pipelinq it lists dossiq's own three read only and says nothing can be saved.",
	},

	CasePublishDialog: {
		kind: 'modal',
		component: CasePublishDialog,
		propsSchema: {},
		_note: 'CaseDetail More menu, Publication group: what a decided case publishes, counted per assessment, then one press (board DqPubliceren). The groups, their notes and the group that never goes along come from the action props.',
	},

	// @spec openspec/specs/status-transition-engine/spec.md
	// @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	CaseLifecycleMenuDialog: {
		kind: 'modal',
		component: CaseLifecycleMenuDialog,
		propsSchema: {},
		_note: 'One menu holding every lifecycle act on the case (REQ-LIFE-10). The acts used to sit in three places, each gated differently, so a handler found out what they could do by trying. It merges /available-transitions, /lifecycle and /acts into one list and DERIVES NOTHING: every disabled and every reason is copied from a server answer. An act the handler may not perform is SHOWN disabled with the reason, never hidden, because the reason is what tells them who to ask. THAT IS NOW THE ONLY LIFECYCLE SURFACE. This note used to end "CaseLifecycleActionDialog stays: the stages widget opens it directly for Resume", and it did not: the stages widget is the library `stages` widget, which cannot resolve a dossiq registry name, and no manifest action named that dialog either. It is retired in retire-the-dead-dialogs. Every gesture it offered, Suspend, Resume, Extend term and Reopen, is here, with the same reason prompt, an empty reason refused, and the same /lifecycle read before the post.',
	},

	// @spec openspec/changes/case-merge/specs/case-management/spec.md
	CaseMergeDialog: {
		kind: 'modal',
		component: CaseMergeDialog,
		propsSchema: {},
		_note: "Merging two cases into one (REQ-CM-37). The survivor is searched for and picked, never defaulted, because the reversal window is seven days and a preselected survivor is one that gets confirmed. The refusals are the server's and are shown verbatim beside their code: a signed beschikking and an already merged case are two different answers with two different ways out. WHY THE ACTION IS HIDDEN ON A CLOSED CASE, moved here from the manifest entry: a closed case is a record of what was decided. The other two refusals, a signed beschikking and a case that was already merged, are the server's and arrive as a sentence in the dialog; hiding them in the header too would leave a handler wondering why an act they were told about is not there. The manifest entry carries no `_note` because `$defs/action` sets `additionalProperties: false`, which is the same reason CasePlanFollowUpDialog above records.",
	},

	// @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
	GatherDocumentsDialog: {
		kind: 'modal',
		component: GatherDocumentsDialog,
		propsSchema: {},
		_note: "Gather documents on a Woo request case (woo-requests-gather-documents-from-sources). Searches files and other cases through Nextcloud unified search with the handler's own access, and integriq's Microsoft 365 source through dossiq. Which provider and filters each platform source uses is the answer of GET /woo/sources, so the dialog guesses nothing. A source that cannot answer is listed with its reason, never as an empty result. Each pick is added or refused on its own; the refused ones stay on screen with the server's sentence. The header action shows on the seeded Woo request case type only (WooRequestIntake::CASE_TYPE_ID) and not on an archived case; the manifest carries no _note because $defs/action refuses one.",
	},

	// @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-handler-assesses-several-documents-in-one-dialog-req-das-002
	DocumentAssessDialog: {
		kind: 'modal',
		component: DocumentAssessDialog,
		propsSchema: {},
		_note: "Assess documents from the case page (document-assessment REQ-DAS-002), built to the Wat nog openstaat table of board DqWooReview. Generic (decision 182): the header action that opens it carries the configuration in its props, namely where the outstanding list is read, where the assessment is posted, the classifications and which of them need a ground, the schema the grounds are read from, and the names of the posted fields. The Woo request case type configures it as `document-assess`; no code names the procedure. The selection is posted once. A refusal keeps the dialog open with the server's sentence beside the document, and the list is read again, so nothing reads as saved that the server did not store. The manifest carries no _note because $defs/action refuses one.",
	},

	// @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003
	AssessmentDecisionDialog: {
		kind: 'modal',
		component: AssessmentDecisionDialog,
		propsSchema: {},
		_note: 'Decide a case from the assessments of its documents (document-assessment REQ-DAS-003). Generic (decision 182): the header action carries the title, the outstanding route, the schema the assessments are counted from, the classifications and the decision route in its props. The Woo request case type configures it as `assessment-decide`. It shows the number of documents per classification, names every document that still waits and offers nothing to save while one does, or while the outstanding list cannot be read. On success it asks the page to refresh, because what the page offers next follows from what the server wrote: the Woo decision route sets wooPublicationStatus to ready, which hides this action and shows Publish (Woo). The manifest carries no _note because $defs/action refuses one.',
	},

	// @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
	WooSearchPlanDialog: {
		kind: 'modal',
		component: WooSearchPlanDialog,
		propsSchema: {},
		_note: "The search plan of a Woo request (woo-request-corpus-collection REQ-WRC-001): whose files, which systems, the period and the terms, recorded before collection. Gather documents refuses to search or add until it is recorded. Recording again changes the same plan object, so the history under the form is that object's OpenRegister audit trail. Shown on the seeded Woo request case type only; the manifest carries no _note because $defs/action refuses one.",
	},

	// @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-from-the-case-page-req-rml-003
	RemedyLodgeDialog: {
		kind: 'modal',
		component: RemedyLodgeDialog,
		propsSchema: {},
		_note: "Lodge a remedy against the decision of a case, from the case page (remedy-lodging REQ-RML-003). Generic (decision 182): the case type's remedy declaration says which case type a remedy opens and within how many days, and the server reads it; the header action carries the title, the post route, the channels and the page that opens the new case in its props. The Woo request case type configures it as `remedy-lodge` with the title Register an objection. Whether the case has a decision is the server's answer, shown as its sentence: the case carries no field a local visibleWhen could read for it. A late lodging is registered and the dialog says so before the handler moves on. The manifest carries no _note because $defs/action refuses one.",
	},

	// @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	CaseSplitDialog: {
		kind: 'modal',
		component: CaseSplitDialog,
		propsSchema: {},
		_note: "Dividing one case in two (REQ-CM-50). The inverse of CaseMergeDialog and a different act from CaseCopyDialog: a copy opens a second case holding everything, so both claim the same document, and a split MOVES what is ticked and leaves a reference behind. Nothing is ticked for the handler, because which document belongs to which half is a judgement about content and a default selection is a judgement that gets confirmed. A party ticked as moving AND as on both halves is two contradictory instructions, so the button refuses rather than the server picking one. The refusals are the server's and shown verbatim, and the one the case type produces is CaseSplitPolicy's own sentence, which names what may still be divided as well as what may not. The header action that opens it is hidden on a CLOSED case, for the same reason the merge is: a closed case is a record of what was decided. That reasoning lives here and not in the manifest because the v2 manifest schema rejects `_note` on a header action.",
	},

	// --- Copy a case, from its own page (case-actions-menu, row A24). ---
	// @spec openspec/specs/case-management/spec.md
	CaseCopyDialog: {
		kind: 'modal',
		component: CaseCopyDialog,
		propsSchema: {},
		_note: "CaseDetail Actions menu: what the copy is called, and whether the source's documents come along. NOT CnCopyDialog, which the design named: the library's 2.41.0 copy dialog offers three naming PATTERNS over a fixed name and carries no slots at all, so there is nowhere to put the Include documents checkbox and no way to type a title that is not one of the three. It reads the case from the ROUTE, because an open-modal action forwards its props verbatim and `@objectId` would arrive as that literal string. Deleted the day nextcloud-vue ships a `copy` header-action type taking a field list and an endpoint (tasks 1.4).",
	},

	// --- Sharing a case with, or handing it to, another organisation (board DqZaakSamenwerken). ---
	// @spec openspec/specs/federated-case-collaboration/spec.md
	CaseShareOrganisationDialog: {
		kind: 'modal',
		component: CaseShareOrganisationDialog,
		propsSchema: {},
	},

	// --- Adding a location to a case from the BAG (board DqZaakLocatie). ---
	// @spec openspec/specs/case-dashboard-view/spec.md
	CaseLocationDialog: {
		kind: 'modal',
		component: CaseLocationDialog,
		propsSchema: {},
	},

	// --- The case as Open Register stored it (admin-inspect-entry, row 2.22). ---
	// @spec openspec/changes/admin-inspect-entry/specs/case-management/spec.md
	CaseRawDataDialog: {
		kind: 'modal',
		component: CaseRawDataDialog,
		propsSchema: {},
		_note: 'The Raw data entry of the CaseDetail Inspect action. NOT CnObjectMetadataModal, which the design named: that component takes a REQUIRED `objectData` object and an open-modal action forwards its props verbatim, so the manifest has no way to hand it the case, and what it renders is the `@self` block rather than the record, so it would answer who owns the case and never show a stored property. Deleted the day nextcloud-vue ships a metadata modal that resolves its own object from the page context. It reads the case from the ROUTE, for the reason CaseCopyDialog does. Hiding it from a handler is an affordance and not a control: the fetch goes to OpenRegister, which refuses on its own, and the dialog prints the refusal rather than opening empty.',
	},

	// --- Start a sub-process for the case (case-actions-menu, row A25). ---
	// @spec openspec/specs/workflow-definition-engine/spec.md
	CaseStartFlowDialog: {
		kind: 'modal',
		component: CaseStartFlowDialog,
		propsSchema: {},
		_note: "CaseDetail Actions menu: the flows this case's TYPE lists in startableFlows, and Run. The run is posted straight to OpenRegister's /api/flows/{id}/run with the case as `{uuid, register, schema}` — the three keys FlowRunRow reads — so it lands in the Flow runs widget beside it and dossiq stores no copy of a run (ADR-022). The Start entry is hidden by the case's materialised `hasStartableFlows`; the dialog still says so when the list comes back empty, because the gate is a save-time value and a case type edited since the last case save has not been recomputed yet.",
	},

	// --- Who has held this case, and who is asking for it (CaseDetail). ---
	// Keyed by TYPE, like every other tab child: a tab panel resolves through
	// `resolveRegistryRenderer`, which reads `cnRegistry[widget.type]` and
	// nothing else. A page `slots` map never reaches a widget inside a tab, and
	// a widget that shipped as `type: "custom"` would draw an empty panel with
	// no warning at all.
	// @spec openspec/specs/case-management/spec.md
	'case-custody-pane': {
		// @custom-widget-ratchet exclude the panel joins TWO surfaces that no declarative widget spans: the chain of holdings, whose rows are only meaningful as a JOIN between one holding's end and the next one's start, and the takeover requests beside it, whose answer is a POST carrying a required reason. A data widget builds its fields from one schema's properties and would draw each holding as a row of values with no way to show the join. Deleted the day the manifest vocabulary has a chain widget over a dated record
		kind: 'widget',
		// A card of its own in the Archiving tab stack, titled from the definition.
		chrome: 'card',
		component: CaseCustodyPanel,
		...PANEL_WIDGET_META,
		_note: 'CaseDetail Custody tab: every period this case was held, with both ends of each holding, and the takeover requests beside them with accept and refuse. Fails CLOSED on a refusal: a reader who may not open the case is told so, never shown an empty chain, because "this case never changed hands" and "you may not see this" look identical from an empty list. The panel writes no holding: an accept answers a REQUEST and the move opens the holding server-side.',
	},

	// The compare dialog a file set's Compare row action opens (the library's
	// field-list widget resolves `dialog` here through cnOpenModal).
	// @spec openspec/specs/document-compare/spec.md#requirement-an-original-and-the-file-that-went-out-are-compared-side-by-side-req-dcp-001
	DocumentCompareDialog: {
		kind: 'modal',
		component: DocumentCompareDialog,
		propsSchema: {},
		_note: "Opened from the items of a frozen file set (the Woo delivered set page declares a field-list widget whose Compare row action names this modal), with `filesUrl` filled per item. It mounts filinq's OCA.Filinq.mountCompare over the original and the file that went out; without filinq it says so and offers both files as links.",
	},

	// --- Plan a follow-up case (case-actions-menu, row A26). ---
	// @spec openspec/specs/workflow-definition-engine/spec.md
	// --- Dialogs the library widgets open on the case page. ---
	//
	// Opened by an `open-modal` action of a library widget (record-list
	// rowActions, facts emptyActions), which resolves `@objectId` and
	// `@object.<field>` before it dispatches and hands a list row in as `row`.
	// @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md
	FileContactMomentDialog: {
		kind: 'modal',
		component: FileContactMomentDialog,
		propsSchema: {},
		_note: 'CaseDetail Communication tab, the contact moments from pipelinq: files one moment on another case too. Opened from the record list row actions with `caseId` and the moment as `row`; pipelinq records who filed it and when, and its refusal is shown in the dialog. Asks the page to refresh on success, so the list reads again.',
	},
	LinkProgrammeDialog: {
		kind: 'modal',
		component: LinkProgrammeDialog,
		propsSchema: {},
		_note: 'CaseDetail Related tab, the Programme card: hangs this case under a pipelinq programme. Opened from the facts card\'s empty action with `caseId` and `caseTitle`; pipelinq\'s refusal names the programme that already holds the case. Asks the page to refresh on success.',
	},

	CasePlanFollowUpDialog: {
		kind: 'modal',
		component: CasePlanFollowUpDialog,
		propsSchema: {},
		_note: 'CaseDetail Actions menu and the Related cases tab: a case type, a date and a title, posted to /plan, which writes ONE scheduled flow creating the case on that date. The earliest date is tomorrow, because a schedule fires on a cron minute and a follow-up planned for today would fire in a few hours or not at all depending on the clock. Single-shot is kept by PlannedFollowUpSweepJob, not by the cron: five cron fields cannot say "once" or "three times". A Repeat picker turns it into a series (planned-case-series): the recurrence becomes the cron fields, the end becomes the sweep\'s stop rule, and the Related tab grows a series row with a Stop series action. The form lives here and not in the manifest: an `open-modal` header action carries a target and props only, and the five fields (case type, date, title, Repeat, Ends) are bound to each other, since the end fields appear only once a repeat is chosen and no `visibleWhen` on a header action can say that. What the manifest does decide is that the gesture is a modal rather than a `handler`, because a handler action resolves `action.handler` against `effectiveManifest.actions`, a JSON map that cannot hold a function, so the entry would warn to the console and do nothing when clicked. The manifest entry itself carries no `_note`: the v2 schema sets `additionalProperties: false` on a header action, so the rationale belongs in this file.',
	},

	// --- Remind a colleague about this case (case-reminder-as-task, row 8.4). ---
	// @spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md
	RemindDialog: {
		kind: 'modal',
		component: RemindDialog,
		propsSchema: {},
		_note: "CaseDetail header action: who, when and what, creating an ENGINE task on the case with kind `reminder`. There is no reminder record and no dossiq job, and that is the design rather than an omission: the engine's due window already answers what is coming up and its assignment notification already tells the colleague, so a second clock in dossiq would disagree with the badge the engine decided the first time one of them was wrong. The kind is the only mark a reminder carries, it is an indexed column on openregister_tasks (openregister#3863), and the Tasks sidebar facets on it. Nothing else treats the value specially, which means a reminder written with the wrong spelling is still created, assigned and notified and is simply missing from the one lens that exists to find it; `REMINDER_KIND` in src/utils/reminderHelpers.js is the single spelling all three surfaces read. The form lives here and not in the manifest because an `open-modal` header action carries a target and props only, and it reads the case from the ROUTE because open-modal forwards props verbatim, so `@objectId` would arrive as that literal string. Who defaults to the signed-in user: most reminders are the one you set for yourself. The manifest entry carries no `_note`: the v2 schema sets `additionalProperties: false` on a header action.",
	},

	// --- The duplicate warning at intake (duplicate-warning-at-intake). ---
	// @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
	caseCreateWithDuplicateCheck: {
		kind: 'create-override',
		handler: createCaseWithDuplicateCheck,
		_note: "Named by `createOverride` on every `new-case` open-form action. It owns the persist, so it can ask OpenRegister whether a case like this one already exists BEFORE the case is written, and show the matches with a link to each. It is not the enforcement: DuplicatePolicy refuses a blocked create on the pre-persist event, so the mail intake, an import and any integration are refused the same way. A createOverride runs on the press rather than on the keystroke, which is the one part of REQ-FCF-10 this seam cannot give: disabling the library dialog's own Create button needs a `beforeConfirm` hook in @conduction/nextcloud-vue.",
	},

	// --- Send digital post, the CaseDetail header action (digital-post-consumes-integriq). ---
	// @spec openspec/specs/berichtenbox-integration/spec.md
	BerichtenboxComposeDialog: {
		kind: 'modal',
		component: BerichtenboxComposeDialog,
		propsSchema: {},
		_note: "Composes one letter to a citizen's digital post and posts it to BerichtenboxController#send, which hands it to IntegriqAdapter and integriq's typed send command. UNTIL 2026-09-18 THIS FILE WAS REFERENCED NOWHERE IN DOSSIQ: not here, not by src/manifest.json, not by another component, and the only occurrence of its name in the repository was its own `name:` line. The CaseDetail `send-digital-post` header action opens it now, and tests/vitest/registryOrphans.spec.js fails on a registered modal that no manifest action names, so it cannot go dark again quietly. It takes `open` beside its older `show` because `open` is what every other registry modal on this page is opened with and an action declaring the wrong one of the two would mount a dialog that renders nothing; `caseId` arrives as the unresolved `@objectId` token, as it does for BeschikkingComposerDialog, and the route answers instead. The recipient is read off the case's `initiatorSourceId` and only for an `initiatorType` of person, because a KvK number is not an address a letter can go to. A REFUSAL DOES NOT CLOSE IT: `sent` is emitted only for a send carrying a tracked message, and the provider's own sentence is shown, because a handler told which credential is missing can ask for it and a handler told \"sending failed\" cannot.",
	},

	// --- Message the applicant, the CaseDetail header action (communication-portal-conversation-on-the-case). ---
	// @spec openspec/specs/portal-contribution/spec.md
	PortalMessageDialog: {
		kind: 'modal',
		component: PortalMessageDialog,
		propsSchema: {},
		_note: "Writes one portaalBericht from the handler to the applicant's portal inbox. Opened by the CaseDetail `message-applicant` header action (visible only on a case with a portalSubject) and by Reply on a resident's message in the timeline tab, which closes that message's follow-up on `sent`. recipientRef is the case's portalSubject, never a user id: the inbox is scoped on it. caseId may arrive as the unresolved `@objectId` token and the route answers instead, as for BerichtenboxComposeDialog.",
	},

	// --- Generate document, the CaseDetail header action (documents-on-the-case). ---
	// @spec openspec/specs/beschikking-generatie/spec.md
	BeschikkingComposerDialog: {
		kind: 'modal',
		component: BeschikkingComposerDialog,
		propsSchema: {},
		_note: "Picks a template from TemplateController#index and has Filinq render the letter and files it on the case through CaseDocumentGenerationService. Opened by the CaseDetail `generate-document` header action as `type: open-modal`, the interim for a `run-action` the library cannot dispatch yet (design D4). The action passes `open: true` because CnAppRoot mounts a registry modal with the action's props verbatim, and the dialog renders on `open`; `caseId` is passed for the same reason and IGNORED when it still holds the unresolved `@objectId` token, because open-modal resolves no tokens.",
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
		propsSchema: {},
		_note: "Upload metadata dialog. Opened by the Documents tab's object-list `dropZone`/upload-button action as `type: open-modal`, which hands over `props.files` (the dropped or picked File[]) the same way a header action's `open-modal` props arrive -- verbatim, no `@`-token resolution. `caseId` is passed for the same reason BeschikkingComposerDialog's is, and falls back to the route when it still holds the literal token. Self-sufficient: fetches the informatieobjecttype catalog and performs the upload itself, since there is no parent DossierTab any more to do either.",
	},
	// @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
	FileRequestDialog: {
		kind: 'modal',
		component: FileRequestDialog,
		propsSchema: {},
		_note: "Ask a party of the case for a file. Opened by the Files tab's `newActions` entry as `type: open-modal`, so the registry mounts it with the action's props (the folder's path and whatever the manifest declared) and nothing else; it reads the case from `caseId` or the route and fetches the parties itself. The recipients are the people linked to the case (people-on-the-case), because Nextcloud's own file request can name nobody: it asks for an address a handler has to know by heart. A party with no address is listed and disabled with the reason.",
	},
	// @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-the-who-can-see-this-card-shows-the-exception-and-changes-it
	DocumentAccessExceptionDialog: {
		kind: 'modal',
		component: DocumentAccessExceptionDialog,
		propsSchema: {},
		_note: "Who may see one document (board DqDocumentenBeheer, the Who can see this card's Change exception). Opened by the case-files side card action as open-modal; CnFilesBrowser merges the selected file's fileId and fileName onto the props and the case's dossier listing names the document. Offered only on a row whose `canChangeAccess` is true (visibleIf.row), the same right the server checks: whoever may change every case of the document. Saving restricts the file in OpenRegister too.",
	},
	BulkDocumentActionDialog: {
		kind: 'modal',
		component: BulkDocumentActionDialog,
		propsSchema: {},
		_note: "Mark final / Change confidentiality / Download ZIP, one dialog in three `mode`s (mirrors BulkTransitionDialog). ITS PARENT IS GONE: the Documents tab whose object-list `bulkActions` opened it was retired on 2026-09-13 and replaced by the `case-files` leaf, and this note went on naming it for five days while no manifest action named this dialog at all. tests/vitest/registryOrphans.spec.js fails on that now. Opened today by the `case-files` `rowActions` entries Mark as final and Change confidentiality, as `type: open-modal`; CnFilesBrowser merges the clicked node's `fileId`, `fileName` and `path` onto the declared props, so the dialog resolves ONE informatieobject through the case's dossier listing rather than a selection of join ids. The `selectedIds` path stays for a widget object-list that still hands a selection over, and takes precedence when it is non-empty. `zip` mode has no caller here on purpose: the whole case file as one download is the CaseDetail `export-dossier` header action, and two buttons for one zip is a worse answer than one.",
	},
	DossierExportPanel: {
		kind: 'modal',
		component: DossierExportPanel,
		propsSchema: {},
		_note: "The Awb-ordered dossier a griffier submits to the bestuursrechter, read-only, opened by the Dossier for the court header action on BeroepDetail and BezwaarDetail. NOT the same thing as CaseDetail's Export dossier: that one is the whole case file as a zip over ZaakdossierDownloadController, and this one is GET /api/dossier/{caseId}/export, which answers a JSON PLAN from BeroepDossierExport with every document of the bezwaar and beroep chain renamed 01-primair-besluit.pdf and so on. That route had answered since the bezwaar-beroep work shipped and no line of src/ had ever called it, so the order a court reads first was one nobody could check. The panel tells a 403 apart from an empty dossier, because both render as nothing and only one of them means the case has no documents.",
	},
	VersionHistoryPanel: {
		kind: 'modal',
		component: VersionHistoryPanel,
		propsSchema: {},
		_note: "Version history for one dossier document, over the Nextcloud Files versions WebDAV API. ITS PARENT IS GONE, the same way BulkDocumentActionDialog's is: the object-list `rowActions` Versions entry that opened it belonged to the Documents tab retired on 2026-09-13, and nothing has named this dialog since. Opened today by the `case-files` `rowActions` Versions entry, where CnFilesBrowser merges the clicked node's `fileId`, `fileName` and `path` onto the declared props; the older `props.row` path (the zaakinformatieobject row with `informatieobject` inlined by `content.extend`, nextcloud-vue#1117) is still read, second, for a widget object-list that passes one. Handed neither, the panel names the file it could not find and offers Show in Files rather than rendering an empty version list. Self-sufficient: the signed-in user comes from `getCurrentUser()`, since there is no parent DossierTab any more to pass it down.",
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
	// Keyed by the TYPE the manifest names, unlike the `component:` entries
	// further down, which the sidebar resolves by component name instead.
	// @spec openspec/specs/case-dashboard-view/spec.md
	// @spec openspec/specs/case-dashboard-view/spec.md
	// @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	'case-conversations-pane': {
		// @custom-widget-ratchet exclude the surface is an ACT, not a collection of OpenRegister objects: it starts a Talk room, declares the case major and opens the one channel that declaration made, and a built-in object-list takes a register and a schema and offers no button. The records it lists live on the case itself as `case.conversations`, which no widget type can read as a collection either. Deleted the day the library ships a widget type that posts to an app endpoint and renders the array a field holds
		kind: 'widget',
		// A card of its own on the side column and in a tab stack, titled from the definition.
		chrome: 'card',
		component: CaseConversationsPanel,
		...PANEL_WIDGET_META,
		_note: 'The Live conversation section of the Communication tab: start a conversation in Talk from any case, see what the case recorded of the ones already held, and declare the case major. The hoorzitting reaches the same mechanism through HearingService; this is the surface for every other case. Absent Talk, it says so rather than offering a button that cannot work.',
	},

	// --- Case assistant via Hermiq (case-assistant-via-hermiq). ---
	// @spec openspec/specs/case-assistant-via-hermiq/spec.md

	// --- CMMN adaptive case plan (cmmn-adaptive-case). ---
	// @spec openspec/specs/cmmn-adaptive-case/spec.md

	// --- Besluitvorming workflow views. ---
	// The agenda compiler and the vergadering detail view were retired: decidiq
	// owns agenda-building and meetings, and surfaces them on a case through the
	// `decidesk-decisions` integration leaf rather than through pages here.

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
	// CaseDecisionsTab was retired by dossiq-decisions-to-decidiq: it offered
	// create/edit/delete of local decision records while mounted on no page.
	// Decisions are authored in decidiq (besluitvorming leaf); the read-only
	// case-decisions widget displays the outcomes stored on the case.

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
		...HEADLESS_WIDGET_META,
		_note: 'CaseDetail actions slot, headless: fills initiatorType, initiatorSourceId and initiatorDisplayName from the canonical requester uuid when the case carries the reference and no projection. Renders nothing.',
	},

	// @spec openspec/changes/woo-review-triage/specs/document-review/spec.md
	'document-relevance-review': {
		// @custom-widget-ratchet exclude document review reads five schemas together (documentReview, reviewBatch, stoppingRule, recallSample and the case type's documentReview block) through dossiq's review routes, which compute the outstanding list, the unseen required pages and the recall estimate on the server. No declarative widget joins them or computes those. Deleted the day a widget type can render a server-computed summary with child collections and actions
		kind: 'widget',
		component: DocumentReviewPanel,
		...PANEL_WIDGET_META,
		_note: 'CaseDetail, Review tab (board DqWooReview): relevance apart from the verdict, the stopping rule and the recall samples, the batches and their reviewers, and what is still open. Generic per decision 182: any case type that sets documentReview.enabled gets it; on any other case it says in one sentence that this case type does not review its documents. It derives no verdict: counts, unseen pages and estimates come from the server.',
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
	// --- AI features on the case (ai-features-on-the-case-consume-hermiq). ---
	// A `component:` tab for the same reason CaseTermsTab is: no CnObjectSidebar
	// built-in can read the case type's declaration, ask hermiq where a feature
	// runs, run one on a document, or read the report group.
	// @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md
	CaseAiFeaturesPanel: {
		kind: 'page',
		component: CaseAiFeaturesPanel,
		_note: "Board DqZaakAssistent, the assistant panel. The AI features the case type places on the case surface, each with the provider and residency hermiq answers (unavailable, never local, when hermiq does not offer it); a run on a chosen case document in the handler's own session through caseAiFeatureGateway, a refusal shown naming the feature, the reason and hermiq's gate; and the report group the case was placed in on creation, read and never placed again, with the count and the near-duplicates hermiq was unsure about.",
	},
	CaseSharingTab: {
		kind: 'page',
		component: CaseSharingTab,
		_note: 'Partner + federated case sharing, transfer and activity — container that wires ShareTab/CreateShareDialog/CaseTransferDialog/CreateFederatedShareDialog/FederatedActivityPanel to the backend API.',
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
	// bespoke per-case Leaflet surface (LocationTab → CaseMap). The map of
	// many cases is the Cases index's own map view (`/cases?_view=map`).
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

	// --- Pages that were only ever in the legacy customComponents map. ---
	//
	CaseTypeFlowView: {
		kind: 'page',
		component: CaseTypeFlowView,
		_note: 'The workflow canvas of one case type, opened from View as flow and Add a task on its Phases and tasks tab (case-type-page-follows-its-boards).',
	},
	MailIntakeLogView: {
		kind: 'page',
		component: MailIntakeLogView,
		_note: 'Named by src/manifest.d/37-mail-intake.json. A custom page because the intake-role check lives in MailIntakeController, and an index page would read the generic object endpoint and show every processed message original to anyone the register lets read.',
	},
	MailPartiesReportWidget: {
		kind: 'widget',
		component: MailPartiesReportWidget,
		...DASHBOARD_TILE_META,
		_note: 'The mail parties report on the reports dashboard (src/manifest.d/86-document-review-reports.json): CnCountTablesReport configured for one case. A registry widget because the report body asks for its case before it reads.',
	},
	// The token-addressed advice-response page is gone with
	// case-sharing-mints-access-links: nothing ever minted the token it read,
	// so the route could not be entered. An advisory body now answers over an
	// OpenRegister access link declaring `comment` (openregister#3817).

	// --- Named behaviours: a manifest `handler` string, not a component. ---
	//
	// `kind: 'handler'` entries expose a plain function as `.handler`, which is
	// what resolveRegisteredHandler reads for `config.actions[].handler` (rows),
	// `config.bulkActions[].handler` and `config.headerActions[].handler`. They
	// are resolved by name and never mounted, so they carry no component and no
	// grid metadata.
	//
	// Every one of them is a function rather than a declarative action because
	// the declarative path cannot express what it does — the reason is on each
	// entry.
	// @spec openspec/changes/case-claim-action/specs/case-management/spec.md
	// @spec openspec/changes/kanban-board-keyboard-status-transition/specs/dashboard/spec.md#requirement-req-dash-v1-006-workflow-board-view-v1
	moveCaseStatus: {
		kind: 'handler',
		handler: moveCaseStatus,
		_note: "The Cases board view's `board.moveHandler`. A status is the output of a transition, so a drop asks the status-transition engine for the offered transition that ends on the dropped status and posts it, the same write path the case page uses. A PATCH of `status` would skip every guard, side effect and statusRecord.",
	},
	caseStatusTargets: {
		kind: 'handler',
		handler: caseStatusTargets,
		_note: "The Cases board view's `board.moveTargetsHandler`: every status the engine offers one case, from /available-transitions. One a guard holds comes back as not allowed with the guard's sentence, so the board fades it during a drag and the card menu says what holds the case.",
	},
	claimCase: {
		kind: 'handler',
		handler: claimCase,
		_note: "The Queue's and Cases' claim row action. The row dispatcher knows neither `api-call` nor a token-resolving write, so a declarative claim would either do nothing or store the literal string `@me`.",
	},
	// @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	markCaseRead: {
		kind: 'handler',
		handler: markCaseRead,
		_note: "The Queue's and Cases' mark-read row action. The row dispatcher knows only `navigate`, `open-page` and a handler NAME, so a declarative `api-call` would render a menu item that does nothing when clicked.",
	},
	markCaseUnread: {
		kind: 'handler',
		handler: markCaseUnread,
		_note: "The Queue's and Cases' mark-unread row action, for the reason markCaseRead is a function.",
	},
	// @spec openspec/specs/case-management/spec.md#requirement-you-follow-a-case-you-do-not-own-req-cm-40
	toggleCaseFollow: {
		kind: 'handler',
		handler: toggleCaseFollow,
		_note: "The Queue's and Cases' follow row action, which replaced the star (one-follow-control). ONE entry rather than a follow and an unfollow, because `@self.watching` rides every row so the click knows which way to go. The gesture is PUT to follow and DELETE to stop on one path, and no declarative write takes two methods.",
	},
	// @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	reassignSelection: {
		kind: 'handler',
		handler: reassignSelection,
		_note: 'The Cases page\'s reassign bulk action. A function because the manifest\'s declarative `handler: "open-modal"` path emits an `open-modal` event that nothing in the library listens for, so declaring it would ship a bulk action that does nothing when clicked.',
	},
	// The four lifecycle bulk actions, all four opening the one
	// BulkTransitionDialog in the matching mode, functions for the reason
	// reassignSelection is one.
	// @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
	transitionSelection: {
		kind: 'handler',
		handler: transitionSelection,
	},
	suspendSelection: {
		kind: 'handler',
		handler: suspendSelection,
	},
	resumeSelection: {
		kind: 'handler',
		handler: resumeSelection,
	},
	extendTermSelection: {
		kind: 'handler',
		handler: extendTermSelection,
	},
	// @spec openspec/specs/admin-settings/spec.md
	openIntegriqConnections: {
		kind: 'handler',
		handler: openIntegriqConnections,
		_note: "The Integrations page's Add integration header action. A function because a header action's `navigate` keyword only pushes a route name inside this app's router, which cannot leave the app.",
	},
	// @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-definitive-penalty-is-prepared-for-payout-from-its-notice-req-ing-case-02
	prepareNoticePayout: {
		kind: 'handler',
		handler: prepareNoticePayout,
		_note: "DqTermijnen notices card `payout-prepare` row action. A function because the object-table widget opens a declared modal without the row, and the payout dialog needs the row's accruing amount; it opens only on a definitive amount.",
	},
	// @spec openspec/specs/kcc-klantcontact-integratie/spec.md
	openCaseOfDocument: {
		kind: 'handler',
		handler: openCaseOfDocument,
		_note: "The contact and organisation Documents panels' open-case row action. A function because the row is a `dispatch` and the destination is its CASE: `rowRoute` pushes the row's own id, which would navigate to a case page for a uuid no case has, and that looks exactly like a deleted case rather than like a bug.",
	},
	// @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	startCaseConversation: {
		kind: 'handler',
		handler: startCaseConversation,
		_note: "The Contact tab's \"Gesprek starten\" card button: the same start the live-conversation panel runs (utils/caseConversation.js), for the open case.",
	},
	// @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	declareCaseMajor: {
		kind: 'handler',
		handler: declareCaseMajor,
		_note: "The Contact tab's \"Groot incident melden\" card button: the same declaration the live-conversation panel runs (utils/caseConversation.js), for the open case.",
	},
	// @spec openspec/specs/case-email-integration/spec.md
	readFileAsMessage: {
		kind: 'handler',
		handler: readFileAsMessage,
		_note: "The case Files tab's read-as-message row action. A function because CnFilesBrowser's row-action vocabulary is `open-modal` and `handler` and has no `api-call`, so a declared POST would render a menu item that does nothing when clicked; and because the browser has NO per-row condition, so the check that this row is a mail file at all lives in the handler and answers with a sentence rather than by being absent.",
	},

}

export default registry
