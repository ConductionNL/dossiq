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

import MyWorkView from './views/MyWork.vue'
import WerkvoorraadView from './views/Werkvoorraad.vue'
import DoorlooptijdView from './views/DoorlooptijdDashboard.vue'
import WorkflowBoardView from './views/workflow-board/WorkflowBoard.vue'
import AdminRootView from './views/settings/AdminRoot.vue'
import VoorstelDetailView from './views/voorstellen/VoorstelDetail.vue'
import PublicCaseView from './views/public/PublicCaseView.vue'
import PublicAppointmentPage from './views/public/PublicAppointmentPage.vue'
import PublicStatusPage from './views/public/PublicStatusPage.vue'

// Detail-tab components (used as `component:` in sidebarTabs[])
import CaseTasksTab from './components/tabs/CaseTasksTab.vue'
import CaseDecisionsTab from './components/tabs/CaseDecisionsTab.vue'
import CaseDocumentsTab from './components/tabs/CaseDocumentsTab.vue'
import AdviesPanel from './views/cases/components/AdviesPanel.vue'
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

	// --- Migration cost: deferred to a follow-up. ---
	VoorstelDetailView: {
		kind: 'page',
		component: VoorstelDetailView,
		_note: 'Parafeerroute multi-step approver flow; complex enough to defer.',
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
	AdviesPanel: {
		kind: 'page',
		component: AdviesPanel,
		_note: 'Advice/advies panel used in CaseDetail and BezwaarDetail sidebar tabs',
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
}

export default registry
