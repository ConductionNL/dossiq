// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// V2 component registry for procest.
//
// Every entry here corresponds to a manifest `type: "custom"` page or a
// sidebar tab that uses `component:` instead of `widgets[]`. The registry
// maps the string key used in the manifest to a `{ kind, component }` entry
// so CnAppRoot can resolve the component at render time.
//
// Recognised kinds: page, modal, widget, form-field, cell-renderer
//
// Migration notes:
// - `voorstelReminder` is a function (row-action handler), not a component.
//   It cannot be a registry entry and stays in customComponents.js.
// - `VisualWorkflowEditor` is intentionally kept out of the registry because
//   @vue-flow/{core,controls,background} are Vue-3-only and break the Vue 2
//   build. The manifest page WorkflowTemplateEditor remains `type:"custom"` but
//   unresolvable until procest migrates to Vue 3 or a Vue-2-compatible flow lib.
//   See customComponents.js for context.
// - `MapComponent` is kept in customComponents.js for backward compat with any
//   manifest entries that reference it by string outside the registry. No
//   current manifest pages reference MapComponent by key directly; retained as
//   a pass-through.

import { leafTab } from './integrations/leafTabs.js'
import MyWorkView from './views/MyWork.vue'
import WerkvoorraadView from './views/Werkvoorraad.vue'
import DoorlooptijdView from './views/DoorlooptijdDashboard.vue'
import WorkflowBoardView from './views/workflow-board/WorkflowBoard.vue'
import AdminRootView from './views/settings/AdminRoot.vue'
import SubstitutionSettingsView from './views/settings/SubstitutionSettings.vue'
import SubstitutionAdminView from './views/admin/SubstitutionAdmin.vue'
import VoorstelDetailView from './views/voorstellen/VoorstelDetail.vue'
import AgendaCompilerView from './views/besluitvorming/AgendaCompilerView.vue'
import VergaderingDetailView from './views/besluitvorming/VergaderingDetailView.vue'
import BesluitPublicatiePanel from './components/besluitvorming/BesluitPublicatiePanel.vue'
import PublicCaseView from './views/public/PublicCaseView.vue'
import PublicAppointmentPage from './views/public/PublicAppointmentPage.vue'
import PublicStatusPage from './views/public/PublicStatusPage.vue'

// GIS / cases-on-map — full-screen clustered map dashboard + read-only
// single-case geo viewer. Both are thin wrappers over CaseMap; data shaping
// lives in src/services/caseGeoService.js (pure, unit-tested).
// @spec openspec/specs/gis-integration/spec.md
import CasesOnMapView from './views/CasesOnMapView.vue'
import GeoViewer from './components/map/GeoViewer.vue'

// Detail-tab components (used as `component:` in sidebarTabs[])
import CaseTasksTab from './components/tabs/CaseTasksTab.vue'
import CaseDecisionsTab from './components/tabs/CaseDecisionsTab.vue'
import CaseDocumentsTab from './components/tabs/CaseDocumentsTab.vue'

// Deelzaak (sub-case) full-page views — wired via manifest routes
// /cases/:id/deelzaken (list) and /cases/:parentId/deelzaken/:id (detail).
// Modal isolation per ADR-004: DeelzaakCreateModal lives in src/modals/.
// @spec openspec/changes/deelzaak-support/tasks.md#T05
// @spec openspec/changes/deelzaak-support/tasks.md#T06
import DeelzaakList from './views/cases/DeelzaakList.vue'
import DeelzaakDetail from './views/cases/DeelzaakDetail.vue'

// Case-email integration — leaf-first per ADR-022. The sidebar tab
// wraps the EmailThread component (display only), reuses NC Mail as
// the email engine, and triggers prefillDraft via the case-email API.
// @spec openspec/changes/case-email-integration/tasks.md#T12
import CaseEmailTab from './views/cases/components/CaseEmailTab.vue'
import AdviesPanel from './views/cases/components/AdviesPanel.vue'
// Related-case linking — typed peer relations (relevanteAndereZaken) sidebar tab.
// Modal isolation per ADR-004: AddCaseRelationModal lives in src/modals/.
// @spec openspec/specs/related-case-linking/spec.md
import RelatedCasesSection from './views/cases/components/RelatedCasesSection.vue'
// VTH-specific case detail panels
import AdviceRequestPanel from './views/cases/components/AdviceRequestPanel.vue'
import InspectionChecklistPanel from './views/cases/components/InspectionChecklistPanel.vue'
import InspectionPanel from './views/cases/components/InspectionPanel.vue'
// Leges-heffingen: case detail sidebar tab + admin verordeningen page.
import LegesBerekeningPanel from './views/cases/components/LegesBerekeningPanel.vue'
import LegesVerordeningenAdmin from './views/settings/LegesVerordeningenAdmin.vue'

// Zaakportaal "Mijn gemeente" citizen-portal pages (zaakportaal-mijngemeente).
import MijnZakenView from './views/portaal/MijnZaken.vue'
import MijnNotificatiesView from './views/portaal/MijnNotificaties.vue'

// Self-fetching dashboard widgets (also shipped as NC Dashboard-app
// widgets). Registered with kind "widget" so the manifest Dashboard
// page's CnWidgetGrid can resolve them by widgetKey (ADR-036 registry).
import CasesOverviewWidget from './views/widgets/CasesOverviewWidget.vue'
import OverdueCasesWidget from './views/widgets/OverdueCasesWidget.vue'
import MyTasksWidget from './views/widgets/MyTasksWidget.vue'
import DeadlineAlertsWidget from './views/widgets/DeadlineAlertsWidget.vue'
import TaskRemindersWidget from './views/widgets/TaskRemindersWidget.vue'
import StalledCasesWidget from './views/widgets/StalledCasesWidget.vue'

// Dashboard KPI cards (CnStatsBlock-based) + chart wrappers + header
// actions — pipelinq-style dashboard top row. All share cached fetchers
// in services/dashboardData.js so the page loads with one fetch per
// dataset instead of one per widget.
import OpenCasesKpiWidget from './views/widgets/OpenCasesKpiWidget.vue'
import OverdueKpiWidget from './views/widgets/OverdueKpiWidget.vue'
import CompletedKpiWidget from './views/widgets/CompletedKpiWidget.vue'
import MyTasksKpiWidget from './views/widgets/MyTasksKpiWidget.vue'
import StatusChartWidget from './views/widgets/StatusChartWidget.vue'
import CasesByTypeWidget from './views/widgets/CasesByTypeWidget.vue'
import DashboardHeaderActions from './views/dashboard/DashboardHeaderActions.vue'

// Leverancier-zaakportaal — operator-side Vue surface for supplier dashboards.
import LeverancierDashboard from './views/leverancier/LeverancierDashboard.vue'
import TenderList from './views/leverancier/TenderList.vue'
import TenderDetail from './views/leverancier/TenderDetail.vue'
import InvoiceList from './views/leverancier/InvoiceList.vue'
import ContractList from './views/leverancier/ContractList.vue'
import KpiView from './views/leverancier/KpiView.vue'
import ProfileForm from './views/leverancier/ProfileForm.vue'
import MessageThread from './views/leverancier/MessageThread.vue'

/*
 * Grid metadata required for every kind:"widget" entry by the ADR-036
 * registry validator in CnAppRoot. Sizes mirror the manifest layout.
 * `allowedSlots` uses the v2 slot literals.
 */
const KPI_WIDGET_META = {
	defaultSize: { w: 3, h: 2 },
	minSize: { w: 2, h: 2 },
	maxSize: { w: 6, h: 4 },
	allowedSlots: ['body'],
	propsSchema: null,
}
const PANEL_WIDGET_META = {
	defaultSize: { w: 6, h: 4 },
	minSize: { w: 3, h: 2 },
	maxSize: { w: 12, h: 6 },
	allowedSlots: ['body'],
	propsSchema: null,
}
const HEADER_ACTIONS_META = {
	defaultSize: { w: 12, h: 1 },
	minSize: { w: 1, h: 1 },
	maxSize: { w: 12, h: 1 },
	allowedSlots: ['header-actions'],
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
	MyWorkView: {
		kind: 'page',
		component: MyWorkView,
		_note: 'Bespoke 4-tab filter UI mixing case + task entities; no index-page analogue.',
	},
	WerkvoorraadView: {
		kind: 'page',
		component: WerkvoorraadView,
		_note: 'KPI-strip-driven work queue.',
	},

	// --- GIS / cases-on-map (gis-integration). ---
	// @spec openspec/specs/gis-integration/spec.md
	CasesOnMapView: {
		kind: 'page',
		component: CasesOnMapView,
		_note: 'Full-screen clustered map of located cases (filters + GeoJSON export). Map rendering delegated to CaseMap; data via /api/cases/geo with a per-object access guard.',
	},
	// @spec openspec/specs/gis-integration/spec.md
	GeoViewer: {
		kind: 'widget',
		component: GeoViewer,
		_note: 'Read-only embedded single-case map (case-detail Locatie tab). Thin wrapper over CaseMap.',
	},

	// --- Lib gaps: would migrate once lib gains the missing primitive. ---
	DoorlooptijdView: {
		kind: 'page',
		component: DoorlooptijdView,
		_note: 'KPI dashboard with apexcharts; pending lib chart-widget support.',
	},

	// --- Workflow Board — Kanban with drag-to-advance status transitions. ---
	// @spec openspec/changes/dashboard/specs/dashboard/spec.md#REQ-DASH-V1-006
	WorkflowBoardView: {
		kind: 'page',
		component: WorkflowBoardView,
		_note: 'Kanban board: column per non-final status, drag-to-advance via saveObject (RBAC-enforced). No declarative board page type in lib yet.',
	},
	AdminRootView: {
		kind: 'page',
		component: AdminRootView,
		_note: 'Multi-tab admin root; pending lib settings-custom-slot support.',
	},

	// --- Handler vervanging/waarneming (handler-vervanging-waarneming). ---
	// @spec openspec/specs/handler-vervanging-waarneming/spec.md
	SubstitutionSettingsView: {
		kind: 'page',
		component: SubstitutionSettingsView,
		_note: 'User self-service vervanging settings; register/revoke own waarnemer. No index-page analogue (custom form + own-records table).',
	},
	// @spec openspec/specs/handler-vervanging-waarneming/spec.md
	SubstitutionAdminView: {
		kind: 'page',
		component: SubstitutionAdminView,
		_note: 'Coordinator substitution admin + bulk reassignment + capacity action list. Coordinator-gated server-side.',
	},

	// --- Migration cost: deferred to a follow-up. ---
	VoorstelDetailView: {
		kind: 'page',
		component: VoorstelDetailView,
		_note: 'Parafeerroute multi-step approver flow; complex enough to defer.',
	},

	// --- Besluitvorming workflow views. ---
	AgendaCompilerView: {
		kind: 'page',
		component: AgendaCompilerView,
		_note: 'Agenda compiler: available vs agenda panels, hamerstuk/bespreekstuk toggle (besluitvorming-workflow).',
	},
	VergaderingDetailView: {
		kind: 'page',
		component: VergaderingDetailView,
		_note: 'Decision recording per geagendeerd case: stemuitslag, attending members, aanhouden flow.',
	},
	BesluitPublicatiePanel: {
		kind: 'page',
		component: BesluitPublicatiePanel,
		_note: 'DROP/LVBB publication status + retry; embeddable as a case-detail sidebar tab component.',
	},

	// --- Anonymous-public routes (no auth, no main menu). ---
	PublicCaseView: {
		kind: 'page',
		component: PublicCaseView,
	},
	PublicAppointmentPage: {
		kind: 'page',
		component: PublicAppointmentPage,
	},
	PublicStatusPage: {
		kind: 'page',
		component: PublicStatusPage,
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
	CaseDecisionsTab: {
		kind: 'page',
		component: CaseDecisionsTab,
		_note: 'Decisions where decision.case === parent.id',
	},
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
	AdviesPanel: {
		kind: 'page',
		component: AdviesPanel,
		_note: 'Advice/advies panel used in CaseDetail and BezwaarDetail sidebar tabs',
	},

	// --- Related-case linking sidebar tab. ---
	// @spec openspec/specs/related-case-linking/spec.md
	RelatedCasesSection: {
		kind: 'page',
		component: RelatedCasesSection,
		_note: 'Typed peer relations (relevanteAndereZaken) on the case detail; add/view/remove typed links with RBAC-safe masking.',
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

	// --- Zaakportaal "Mijn gemeente" citizen portal (zaakportaal-mijngemeente). ---
	// @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-17
	MijnZakenView: {
		kind: 'page',
		component: MijnZakenView,
		_note: 'Citizen-facing case overview + detail with accessible status timeline; reads IDOR-safe /api/portaal/cases. No declarative analogue (citizen surface, subject-scoped).',
	},
	MijnNotificatiesView: {
		kind: 'page',
		component: MijnNotificatiesView,
		_note: 'Citizen notification-preference manager with statutory Berichtenbox-always-on control.',
	},

	// --- Leges-heffingen: case detail tab + admin page. ---
	// @spec openspec/changes/leges-heffingen/specs.md#req-leges-002
	LegesBerekeningPanel: {
		kind: 'page',
		component: LegesBerekeningPanel,
		_note: 'Leges panel — shows the calculation, audit trail and refund action on case detail',
	},
	LegesVerordeningenAdmin: {
		kind: 'page',
		component: LegesVerordeningenAdmin,
		_note: 'Admin page listing leges tariff tables with import + approve workflow',
	},

	// --- Dashboard widgets — resolved by the Dashboard page's
	// `slots` map (widget-{id} → registry name) on CnDashboardPage. ---
	// Self-fetching via the shared cached fetchers in
	// services/dashboardData.js. The same list components back the NC
	// Dashboard-app widgets. The grid metadata is required for every
	// kind:"widget" entry by the ADR-036 registry validator in
	// CnAppRoot; the dashboard positions widgets via the manifest
	// `config.layout` (GridStack), so these sizes are not consumed at
	// runtime — they mirror the manifest layout for coherence.
	casesOverview: {
		kind: 'widget',
		component: CasesOverviewWidget,
		...PANEL_WIDGET_META,
		_note: 'Open cases list — self-fetching via objectStore.',
	},
	overdueCases: {
		kind: 'widget',
		component: OverdueCasesWidget,
		...PANEL_WIDGET_META,
		_note: 'Cases past their deadline.',
	},
	myTasks: {
		kind: 'widget',
		component: MyTasksWidget,
		...PANEL_WIDGET_META,
		_note: 'Tasks assigned to the current user.',
	},
	deadlineAlerts: {
		kind: 'widget',
		component: DeadlineAlertsWidget,
		...PANEL_WIDGET_META,
		_note: 'Overdue + at-risk case deadlines.',
	},
	taskReminders: {
		kind: 'widget',
		component: TaskRemindersWidget,
		...PANEL_WIDGET_META,
		_note: 'Tasks overdue or due soon.',
	},
	stalledCases: {
		kind: 'widget',
		component: StalledCasesWidget,
		...PANEL_WIDGET_META,
		_note: 'Cases without recent activity.',
	},
	kpiOpenCases: {
		kind: 'widget',
		component: OpenCasesKpiWidget,
		...KPI_WIDGET_META,
		_note: 'KPI card — open (non-final) case count, links to /cases.',
	},
	kpiOverdue: {
		kind: 'widget',
		component: OverdueKpiWidget,
		...KPI_WIDGET_META,
		_note: 'KPI card — open cases past their deadline (error variant when > 0).',
	},
	kpiCompleted: {
		kind: 'widget',
		component: CompletedKpiWidget,
		...KPI_WIDGET_META,
		_note: 'KPI card — cases completed this month + avg processing days.',
	},
	kpiMyTasks: {
		kind: 'widget',
		component: MyTasksKpiWidget,
		...KPI_WIDGET_META,
		_note: 'KPI card — active/available tasks assigned to the current user.',
	},
	statusChart: {
		kind: 'widget',
		component: StatusChartWidget,
		...PANEL_WIDGET_META,
		_note: 'Open cases by status — self-fetching wrapper around StatusChart.',
	},
	casesByType: {
		kind: 'widget',
		component: CasesByTypeWidget,
		...PANEL_WIDGET_META,
		_note: 'Open cases by case type — self-fetching wrapper around CasesByType.',
	},
	DashboardHeaderActions: {
		kind: 'widget',
		component: DashboardHeaderActions,
		...HEADER_ACTIONS_META,
		_note: 'Dashboard header buttons (New Case + Refresh) wired as the Dashboard page actionsComponent.',
	},

	// --- Leverancier-zaakportaal (operator-side) — chain members 06/08/10/11/14/15. ---
	// @spec openspec/changes/leverancier-zaakportaal-15-dashboard-shell/tasks.md
	LeverancierDashboard: {
		kind: 'page',
		component: LeverancierDashboard,
		_note: 'Supplier portal 4-card dashboard shell — tenders/invoices/contracts/KPI',
	},
	TenderList: {
		kind: 'page',
		component: TenderList,
		_note: 'Supplier tender list — sortable/filterable, status badge from TenderViewModelService',
	},
	TenderDetail: {
		kind: 'page',
		component: TenderDetail,
		_note: 'Supplier tender detail — conditional award/rejection/withdrawal sections from visibilityFlags()',
	},
	InvoiceList: {
		kind: 'page',
		component: InvoiceList,
		_note: 'Supplier invoice list — overdue90Plus flag + status badge from LeverancierViewModelService',
	},
	ContractList: {
		kind: 'page',
		component: ContractList,
		_note: 'Supplier contract list — expiring-soon highlighting from ContractRenewalService',
	},
	KpiView: {
		kind: 'page',
		component: KpiView,
		_note: 'Supplier KPI summary — payment days, on-time pct, dispute rate, compliance score',
	},
	ProfileForm: {
		kind: 'page',
		component: ProfileForm,
		_note: 'Supplier profile form — address + contact (immediate) + IBAN-change (4-eyes via Procest case)',
	},
	MessageThread: {
		kind: 'page',
		component: MessageThread,
		_note: 'Per-case supplier message thread with composer — chain member 11 messaging',
	},
}

export default registry
