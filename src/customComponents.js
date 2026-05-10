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
import CaseMapView from './views/CaseMapView.vue'
import DoorlooptijdView from './views/DoorlooptijdDashboard.vue'
import VoorstellenView from './views/voorstellen/VoorstelList.vue'
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

export default {
	// --- Genuine exceptions: no abstract analogue. ---
	MyWorkView,        // bespoke 4-tab filter UI mixing case + task entities
	WerkvoorraadView,  // KPI-strip-driven work queue
	CaseMapView,       // Leaflet map + WMS/WFS layers + marker clusters

	// --- Lib gaps: would migrate once lib gains the missing primitive. ---
	DoorlooptijdView,  // KPI dashboard with apexcharts (lib chart-widget gap)
	AdminRootView,     // multi-tab admin root (lib settings-custom-slot gap)

	// --- Migration cost: deferred to a follow-up. ---
	VoorstellenView,      // status-tabs filter tied to parafeerroute lifecycle
	VoorstelDetailView,   // parafeerroute multi-step approver flow

	// --- Anonymous-public routes (no auth, no main menu). ---
	PublicCaseView,
	PublicAppointmentPage,
	PublicStatusPage,

	// --- Detail-tab components (one per case-detail cross-schema relation). ---
	CaseTasksTab,      // tasks where task.case === parent.id
	CaseDecisionsTab,  // decisions where decision.case === parent.id
	CaseDocumentsTab,  // documents where document.case === parent.id
}
