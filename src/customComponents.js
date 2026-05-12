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
// VoorstellenView removed — the Voorstellen list page is now a declarative
// `type:"index"` on the `voorstel` schema (formatter columns + status badge,
// see src/manifest.json + src/services/formatters.js).
import VoorstelDetailView from './views/voorstellen/VoorstelDetail.vue'
import AdminRootView from './views/settings/AdminRoot.vue'
import PublicCaseView from './views/public/PublicCaseView.vue'
import PublicAppointmentPage from './views/public/PublicAppointmentPage.vue'
import PublicStatusPage from './views/public/PublicStatusPage.vue'

// --- Detail-tab custom components (one per cross-schema relation). ---
// Stubs for v1 — full implementations follow in `procest-case-relation-tabs`.
import CaseTasksTab from './components/tabs/CaseTasksTab.vue'
import CaseDecisionsTab from './components/tabs/CaseDecisionsTab.vue'
import CaseDocumentsTab from './components/tabs/CaseDocumentsTab.vue'

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
	DoorlooptijdView, // KPI dashboard with apexcharts (lib chart-widget gap)
	AdminRootView, // multi-tab admin root (lib settings-custom-slot gap)

	// --- Migration cost: deferred to a follow-up. ---
	VoorstelDetailView, // parafeerroute multi-step approver flow

	// --- Row-action handlers (function entries — dispatched by manifest `handler` id). ---
	voorstelReminder, // Voorstellen index → POST a parafering reminder

	// --- Anonymous-public routes (no auth, no main menu). ---
	PublicCaseView,
	PublicAppointmentPage,
	PublicStatusPage,

	// --- Detail-tab components (one per case-detail cross-schema relation). ---
	CaseTasksTab, // tasks where task.case === parent.id
	CaseDecisionsTab, // decisions where decision.case === parent.id
	CaseDocumentsTab, // documents where document.case === parent.id

	// --- Visual workflow editor — temporarily unwired (see import comment above). ---
	// VisualWorkflowEditor,

	// --- Shared map surface — referenceable from manifest pages. ---
	MapComponent,
}
