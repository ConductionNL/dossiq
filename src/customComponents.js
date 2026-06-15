// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component registry for procest's manifest-driven app shell.
//
// Every entry here is the "escape hatch" — pages or sidebar tabs that
// don't fit one of the manifest's built-in types/widgets. Keep this
// file SHORT. Adding entries should require explicit justification in
// the design doc; deleting them is the right direction.
//
// Resolution order at runtime:
//   1. Built-in page types          (CnIndexPage, CnDetailPage, …)
//   2. Built-in widget types        (version-info, register-mapping, …)
//   3. customComponents (this file) ← consumer-injected components
//
// See:
//   - openspec/changes/procest-manifest-v1/design.md
//   - @conduction/nextcloud-vue → docs/migrating-to-manifest.md

// --- Surviving custom pages — see design.md "Custom-fallback inventory". ---
import MyWorkView from './views/MyWork.vue'
import WerkvoorraadView from './views/Werkvoorraad.vue'
// CaseMapView removed — superseded by manifest `type: 'map'` CnMapPage
// (see openspec/changes/case-map-overview/design.md).
import DoorlooptijdView from './views/DoorlooptijdDashboard.vue'
// --- Termijnbewaking + Archief + Tenant dashboards (chain-builds 06/2026). ---
import TermijnDashboard from './views/dashboard/TermijnDashboard.vue'
import ArchiefDashboard from './views/dashboard/ArchiefDashboard.vue'
import TenantOnboardingDashboard from './views/dashboard/TenantOnboardingDashboard.vue'
// VoorstellenView removed — the Voorstellen list page is now a declarative
// `type:"index"` on the `voorstel` schema (formatter columns + status badge,
// see src/manifest.json + src/services/formatters.js).
import VoorstelDetailView from './views/voorstellen/VoorstelDetail.vue'
import AdminRootView from './views/settings/AdminRoot.vue'
import PublicCaseView from './views/public/PublicCaseView.vue'
import PublicAppointmentPage from './views/public/PublicAppointmentPage.vue'
import PublicStatusPage from './views/public/PublicStatusPage.vue'

// --- Leverancier-zaakportaal (operator-side) — chain members 06/08/10/11/14/15. ---
import LeverancierDashboard from './views/leverancier/LeverancierDashboard.vue'
import TenderList from './views/leverancier/TenderList.vue'
import TenderDetail from './views/leverancier/TenderDetail.vue'
import InvoiceList from './views/leverancier/InvoiceList.vue'
import ContractList from './views/leverancier/ContractList.vue'
import KpiView from './views/leverancier/KpiView.vue'
import ProfileForm from './views/leverancier/ProfileForm.vue'
import MessageThread from './views/leverancier/MessageThread.vue'

// --- Detail-tab custom components (one per cross-schema relation). ---
// Stubs for v1 — full implementations follow in `procest-case-relation-tabs`.
import CaseTasksTab from './components/tabs/CaseTasksTab.vue'
import CaseDecisionsTab from './components/tabs/CaseDecisionsTab.vue'
import CaseDocumentsTab from './components/tabs/CaseDocumentsTab.vue'

// --- Deelzaak (sub-case) full-page views — manifest custom routes. ---
// @spec openspec/changes/deelzaak-support/tasks.md#T05
// @spec openspec/changes/deelzaak-support/tasks.md#T06
import DeelzaakList from './views/cases/DeelzaakList.vue'
import DeelzaakDetail from './views/cases/DeelzaakDetail.vue'

// --- Mobiel-inspectie offline PWA views (mobiel-inspectie-offline). ---
// @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
import InspectieList from './views/inspectie/InspectieList.vue'
import InspectieDetail from './views/inspectie/InspectieDetail.vue'

// --- Case-email sidebar tab (leaf-first per ADR-022). ---
// @spec openspec/changes/case-email-integration/tasks.md#T12
import CaseEmailTab from './views/cases/components/CaseEmailTab.vue'

// --- ZGW DRC case dossier sidebar tab. ---
// @spec openspec/changes/document-zaakdossier/tasks.md#T10
import DossierTab from './views/cases/components/DossierTab.vue'

// --- Visual workflow editor — TEMPORARILY UNWIRED. ---
// `@vue-flow/{core,controls,background}` v1.x are Vue-3-only (they import
// Fragment / Teleport / createElementVNode / toValue from 'vue'), which breaks
// the webpack build under procest's Vue 2.7 base (272 errors). The component
// files remain in src/components/workflow/ but are no longer pulled into the
// bundle. Re-wire once @vue-flow is replaced with a Vue-2-compatible flow
// library (or procest migrates to Vue 3). See
// openspec/changes/visual-workflow-editor/design.md.
// import VisualWorkflowEditor from './components/workflow/VisualWorkflowEditor.vue'

// --- Shared map surface (case detail map tab, dashboard widget, public case page). ---
// Thin wrapper around CnMapWidget; registered here so manifest entries
// MAY reference it by string name. See openspec/changes/map-component/.
import MapComponent from './components/map/MapComponent.vue'

// --- Features & Roadmap page — thin wrapper around the lib's
//     CnFeaturesAndRoadmapView (the in-product roadmap surface powered by
//     OpenRegister's github-issue-proxy). See ConductionNL/hydra#251. ---

/**
 * Row-action handler for the Voorstellen index: POST a parafering-reminder
 * notification for the step the voorstel is currently waiting on. Registered
 * below as a "function" entry so the manifest action
 * `{ id: "reminder", handler: "voorstelReminder" }` can dispatch to it —
 * CnIndexPage calls a function-typed `customComponents[handler]` with
 * `{ actionId, item }` on row-action click. (Replaces the bespoke
 * `sendReminder()` that lived in the deleted VoorstelList.vue.)
 *
 * @param {object} ctx Dispatch context.
 * @param {string} ctx.actionId The action id (`"reminder"`).
 * @param {object} ctx.item The voorstel row.
 * @return {Promise<void>}
 */
async function voorstelReminder({ actionId, item }) {
	const steps = (() => {
		const snap = item && item.routeSnapshot
		if (!snap) return []
		try { return typeof snap === 'string' ? JSON.parse(snap) : snap } catch { return [] }
	})()
	const current = steps.find((s) => s.order === item.currentStep)
	const actor = current ? (current.label || current.actor || '-') : '-'
	try {
		await fetch('/apps/procest/api/notifications/parafering-reminder', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: window.OC?.requestToken,
				'OCS-APIREQUEST': 'true',
			},
			body: JSON.stringify({ voorstelId: item.id, actor, onderwerp: item.onderwerp }),
		})
	} catch (error) {
		// eslint-disable-next-line no-console
		console.error('[procest] parafering reminder failed', error)
	}
}

export default {
	// --- Genuine exceptions: no abstract analogue. ---
	MyWorkView, // bespoke 4-tab filter UI mixing case + task entities
	WerkvoorraadView, // KPI-strip-driven work queue
	// CaseMapView removed — see import comment above.

	// --- Lib gaps: would migrate once lib gains the missing primitive. ---
	DoorlooptijdView, // SLA dashboard — charts via OR analytics-series leaf + lib CnChartWidget (ADR-022)
	AdminRootView, // multi-tab admin root (lib settings-custom-slot gap)
	TermijnDashboard, // AWB termijnbewaking + dwangsom KPI dashboard
	ArchiefDashboard, // e-Depot handover dashboard (stats + triggers + audit)
	TenantOnboardingDashboard, // SaaS tenant onboarding (7-step + go-live)

	// --- Migration cost: deferred to a follow-up. ---
	VoorstelDetailView, // parafeerroute multi-step approver flow

	// --- Row-action handlers (function entries — dispatched by manifest `handler` id). ---
	voorstelReminder, // Voorstellen index → POST a parafering reminder

	// --- Anonymous-public routes (no auth, no main menu). ---
	PublicCaseView,
	PublicAppointmentPage,
	PublicStatusPage,

	// --- Leverancier-zaakportaal (operator-side) — chain members 06/08/10/11/14/15. ---
	LeverancierDashboard,
	TenderList,
	TenderDetail,
	InvoiceList,
	ContractList,
	KpiView,
	ProfileForm,
	MessageThread,

	// --- Detail-tab components (one per case-detail cross-schema relation). ---
	CaseTasksTab, // tasks where task.case === parent.id
	CaseDecisionsTab, // decisions where decision.case === parent.id
	CaseDocumentsTab, // documents where document.case === parent.id

	// --- Deelzaak (sub-case) views (manifest /cases/:id/deelzaken[/...]). ---
	DeelzaakList, // sub-case list for a parent case
	DeelzaakDetail, // sub-case detail with parent breadcrumb

	// --- Mobiel-inspectie offline PWA (daily planning + offline checklists). ---
	InspectieList, // offline daily planning + sync indicator
	InspectieDetail, // offline checklist completion (atomic local store + queue)

	// --- Case-email sidebar tab (display via leaf, compose via NC Mail draft). ---
	CaseEmailTab,

	// --- ZGW DRC case dossier tab (document-zaakdossier). ---
	DossierTab,

	// --- Visual workflow editor — temporarily unwired (see import comment above). ---
	// VisualWorkflowEditor,

	// --- Shared map surface — referenceable from manifest pages. ---
	MapComponent,

	// --- Features & Roadmap page (lib's CnFeaturesAndRoadmapView). ---
}
