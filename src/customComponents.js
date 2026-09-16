// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component registry for dossiq's manifest-driven app shell.
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
import { generateUrl } from '@nextcloud/router'
import { createApp } from 'vue'
import CaseDocumentsTab from './components/tabs/CaseDocumentsTab.vue'
// --- Detail-tab custom components (one per cross-schema relation). ---
// Stubs for v1 — full implementations follow in `procest-case-relation-tabs`.
import CaseTasksTab from './components/tabs/CaseTasksTab.vue'
import BulkTransitionDialog from './dialogs/BulkTransitionDialog.vue'
import ReassignSelectionDialog from './dialogs/ReassignSelectionDialog.vue'
// --- Case-email sidebar tab (leaf-first per ADR-022). ---
// @spec openspec/changes/case-email-integration/tasks.md#T12
import CaseEmailTab from './views/cases/components/CaseEmailTab.vue'
import CaseTimelineTab from './views/cases/components/CaseTimelineTab.vue'
import DeelzaakDetail from './views/cases/DeelzaakDetail.vue'
// --- Deelzaak (sub-case) full-page views — manifest custom routes. ---
// @spec openspec/changes/deelzaak-support/tasks.md#T05
// @spec openspec/changes/deelzaak-support/tasks.md#T06
import DeelzaakList from './views/cases/DeelzaakList.vue'
import DeletedCasesView from './views/cases/DeletedCasesView.vue'
// --- Leverancier-zaakportaal (external supplier portal) MOVED to Portaliq
//     (ADR-046, procest#162): the /leverancier Vue surface is retired here and
//     re-expressed as the `supplier` audience in
//     lib/Portal/PortalContributionProvider.php. The backend supplier services
//     + /api/leverancier-portaal/* endpoints stay; only the in-app portal views
//     and their nav/routes are removed. ---
// CaseMapView removed — superseded by manifest `type: 'map'` CnMapPage
// (see openspec/changes/case-map-overview/design.md).
import DtAtRiskWidget from './views/doorlooptijd/widgets/DtAtRiskWidget.vue'
import DtBreakdownWidget from './views/doorlooptijd/widgets/DtBreakdownWidget.vue'
import DtCaseTypeFilter from './views/doorlooptijd/widgets/DtCaseTypeFilter.vue'
import DtChartsWidget from './views/doorlooptijd/widgets/DtChartsWidget.vue'
import DtKpiWidget from './views/doorlooptijd/widgets/DtKpiWidget.vue'
import DtWooWidget from './views/doorlooptijd/widgets/DtWooWidget.vue'
import FeaturesRoadmapView from './views/FeaturesRoadmapView.vue'
// --- Mail intake log: a custom page because the intake-role check lives in
//     MailIntakeController, and an index page would read the generic object
//     endpoint and show every processed message's original to anyone the
//     register lets read. ---
// @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
import MailIntakeLogView from './views/intake/MailIntakeLogView.vue'
import MyWorkView from './views/MyWorkCards.vue'
import PmBottleneckTableWidget from './views/processMining/PmBottleneckTableWidget.vue'
import PmCaseTypeFilter from './views/processMining/PmCaseTypeFilter.vue'
import PmDwellChartWidget from './views/processMining/PmDwellChartWidget.vue'
import PmKpiWidget from './views/processMining/PmKpiWidget.vue'
import PmThroughputChartWidget from './views/processMining/PmThroughputChartWidget.vue'
import PublicAppointmentPage from './views/public/PublicAppointmentPage.vue'
// Remote-org accept/reject for a federated zaakoverdracht (federated-case-collaboration).
import PublicFederatedTransferPage from './views/public/PublicFederatedTransferPage.vue'
import PublicStatusPage from './views/public/PublicStatusPage.vue'
// --- Store (ADR-080). A store item is a REMOTE object, so the manifest's
//     object-backed index renderer — which resolves a local register+schema —
//     cannot address it. Discovery itself is the engine's, not this file's.
// @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md
import StoreGallery from './views/store/StoreGallery.vue'
// --- Termijnbewaking + Tenant dashboards (chain-builds 06/2026). ---
// Archief dashboard retired (migrate-archival-to-or, ADR-022): the archivist
// views are owned by OpenRegister.
import TdAnnualWidget from './views/termijn/TdAnnualWidget.vue'
import TdCaseTypeFilter from './views/termijn/TdCaseTypeFilter.vue'
import TdKpiWidget from './views/termijn/TdKpiWidget.vue'
import TdQuarterlyWidget from './views/termijn/TdQuarterlyWidget.vue'
import { countMatchingCases } from './services/bulkJobApi.js'
// The Queue's and Cases' Claim row action, in its own module so a unit test
// can reach it without importing every page this file mounts.
// @spec openspec/changes/case-claim-action/specs/case-management/spec.md
import { claimCase } from './utils/caseClaim.js'
// Star a case, or take the star off, from a list row
// (case-number-and-favourites, row 2.19).
// @spec openspec/changes/case-number-and-favourites/specs/case-management/spec.md
import { toggleCaseFavourite } from './utils/caseFavourite.js'
// Mark a case read or unread from a list row (unread-state-on-the-case).
// @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
import { markCaseRead, markCaseUnread } from './utils/caseUnread.js'
import { readLocationFilters } from './utils/selectionScope.js'
// Mobiel-inspectie offline views retired — "Veldinspecties" now surfaces the
// generic `field-inspection` OpenRegister integration leaf (a nc-vue builtin),
// registered with dossiq's offline schema mapping in src/main.js. The custom
// InspectieList/InspectieDetail views + their offline glue (offlineDb.js,
// syncReplayService.js) are deleted; the leaf owns the planning list, checklist
// completion, mutation queue and reconnect-replay.
// --- Features & Roadmap page — thin wrapper around the lib's
//     CnFeaturesAndRoadmapView (the in-product roadmap surface powered by
//     OpenRegister's github-issue-proxy). See ConductionNL/hydra#251. ---

/**
 * Bulk-action handler for the Cases index: reassign the selected cases.
 *
 * CnIndexPage calls a function-typed `customComponents[handler]` with
 * `{ actionId, selectedIds, count }`, so the SELECTION arrives as an argument.
 * That matters: a handler that went and re-read the selection itself would be
 * one re-render away from acting on a different set than the user saw
 * highlighted.
 *
 * The dialog is mounted here rather than declared in the manifest because the
 * library's declarative modal path emits `open-modal` and nothing consumes it.
 *
 * @param {{actionId: string, selectedIds: Array<string>, count: number}} scope The selection.
 * @return {void}
 */
async function reassignSelection({ selectedIds }) {
	const ids = Array.isArray(selectedIds) ? selectedIds : []
	if (ids.length === 0) {
		return
	}

	const { filters, total } = await listScope(ids)

	const host = document.createElement('div')
	document.body.appendChild(host)

	const app = createApp(ReassignSelectionDialog, {
		open: true,
		selectedIds: ids,
		filters,
		matchingTotal: total,
		'onUpdate:open': (open) => {
			if (open === false) {
				app.unmount()
				host.remove()
			}
		},
		onReassigned: () => {
			// The index has to re-read: the rows the user just moved are no
			// longer theirs, and leaving them on screen invites a second
			// reassignment of cases that already moved.
			window.dispatchEvent(new CustomEvent('dossiq:cases-changed'))
		},
	})
	app.mount(host)
}

/**
 * What the case list is showing, beyond the rows the handler ticked.
 *
 * A bulk handler is called with the selection and nothing else, so the whole
 * result set has to be found rather than passed. The filters are in the
 * address bar, and the count comes from OpenRegister.
 *
 * A count that cannot be read comes back as zero, which makes the scope
 * affordance withhold the whole-result offer. That is the right failure: an
 * offer of "select all 400" that cannot say where 400 came from is the exact
 * surprise the affordance exists to prevent.
 *
 * @param {Array<string>} ids The ticked rows.
 *
 * @return {Promise<{filters: object, total: number}>} What the list holds.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
async function listScope(ids) {
	const filters = readLocationFilters()
	const total = await countMatchingCases(filters)

	return { filters, total: total > ids.length ? total : 0 }
}

/**
 * Mount `BulkTransitionDialog` for a selection, in one of its four modes.
 *
 * Mounted here rather than declared in the manifest for the same reason
 * `reassignSelection` is: the library's declarative modal path emits
 * `open-modal` and nothing consumes it, so a manifest-declared dialog would
 * be a bulk action that does nothing when clicked.
 *
 * @param {string} mode One of transition, suspend, resume, extend.
 * @param {Array<string>} selectedIds The selected case ids.
 * @return {void}
 *
 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
 */
async function openBulkDialog(mode, selectedIds) {
	const ids = Array.isArray(selectedIds) ? selectedIds : []
	if (ids.length === 0) {
		return
	}

	const { filters, total } = await listScope(ids)

	const host = document.createElement('div')
	document.body.appendChild(host)

	let app = null

	/**
	 * Tear the mounted dialog down.
	 *
	 * @return {void}
	 */
	function close() {
		app.unmount()
		host.remove()
	}

	app = createApp(BulkTransitionDialog, {
		caseIds: ids,
		mode,
		filters,
		matchingTotal: total,
		onClose: close,
		onCompleted: () => {
			close()
			// Same signal `reassignSelection` sends: the rows the user just
			// moved may no longer belong on the active lens, and leaving them
			// on screen invites a second gesture on cases that already moved.
			// The list's own refresh comes from its live-collection
			// subscription; this event is the app-level notice beside it.
			window.dispatchEvent(new CustomEvent('dossiq:cases-changed'))
		},
	})
	app.mount(host)
}

/**
 * Bulk-action handler: move the selected cases to another status.
 *
 * @param {{actionId: string, selectedIds: Array<string>, count: number}} scope The selection.
 * @return {void}
 *
 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
 */
function transitionSelection({ selectedIds }) {
	openBulkDialog('transition', selectedIds)
}

/**
 * Bulk-action handler: suspend the selected cases (opschorting, Awb 4:5).
 *
 * @param {{actionId: string, selectedIds: Array<string>, count: number}} scope The selection.
 * @return {void}
 *
 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
 */
function suspendSelection({ selectedIds }) {
	openBulkDialog('suspend', selectedIds)
}

/**
 * Bulk-action handler: resume the selected suspended cases (hervatting).
 *
 * @param {{actionId: string, selectedIds: Array<string>, count: number}} scope The selection.
 * @return {void}
 *
 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
 */
function resumeSelection({ selectedIds }) {
	openBulkDialog('resume', selectedIds)
}

/**
 * Bulk-action handler: extend the term of the selected cases (verlenging,
 * Awb 4:14).
 *
 * @param {{actionId: string, selectedIds: Array<string>, count: number}} scope The selection.
 * @return {void}
 *
 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
 */
function extendTermSelection({ selectedIds }) {
	openBulkDialog('extend', selectedIds)
}

/**
 * Where Add integration lands: integriq's overview, preset and linking.
 */
export const INTEGRIQ_CONNECTIONS_PATH =
	'/apps/integriq/connections?app=dossiq&link=1'

/**
 * The Integrations page's Add integration header action.
 *
 * A connection row is integriq's, and a source is linked to it on integriq's
 * Connections overview (hydra connection-registry D9). `link=1` opens the
 * link-a-source dialog there, pre-filtered to dossiq's connections.
 *
 * A FUNCTION handler because a header action's `navigate` keyword only pushes
 * a route name inside this app's router, which cannot leave the app.
 *
 * The route is the one hydra connection-registry D9 names.
 *
 * @return {void}
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md
 */
export function openIntegriqConnections() {
	window.location.assign(generateUrl(INTEGRIQ_CONNECTIONS_PATH))
}

export default {
	// The Queue's and Cases' `claim` row action (case-claim-action, row 2.4).
	// A function handler for the reason the bulk actions below are ones, plus
	// one of its own: the row dispatcher knows neither `api-call` nor a
	// token-resolving write, so a declarative claim would either do nothing or
	// store the literal string `@me`.
	claimCase,
	// The Queue's and Cases' `mark-unread` and `mark-read` row actions
	// (unread-state-on-the-case, tasks 1.2). Function handlers for the same
	// reason `claimCase` is one: the row dispatcher knows only `navigate`,
	// `open-page` and a handler NAME, so a declarative `api-call` here would
	// render a menu item that does nothing when clicked.
	markCaseRead,
	markCaseUnread,
	// The Queue's and Cases' `favourite` row action
	// (case-number-and-favourites). One entry rather than a star and an
	// unstar, because `@self.favourite` rides every row, so the menu item can
	// say what the click will do. A function handler for the same reason the
	// two above are ones, plus one of its own: the gesture is PUT to star and
	// DELETE to unstar on one path, and no declarative write takes two
	// methods.
	toggleCaseFavourite,
	// --- Genuine exceptions: no abstract analogue. ---
	// The Cases page's `reassign` bulk action. A FUNCTION handler, not the
	// manifest's declarative `handler: "open-modal"` path: that path emits an
	// `open-modal` event and nothing in the library listens for it yet, so
	// declaring it would ship a bulk action that does nothing when clicked.
	reassignSelection,
	// The Cases page's four lifecycle bulk actions, all four opening the one
	// BulkTransitionDialog in the matching mode. Function handlers for the
	// same reason `reassignSelection` is one.
	transitionSelection,
	suspendSelection,
	resumeSelection,
	extendTermSelection,
	// The Integrations page's Add integration header action. A FUNCTION
	// handler because it leaves the app for integriq's Connections overview.
	openIntegriqConnections,
	MyWorkView, // current-user case index (assignee=uid) in card view — CnIndexPage wrapper
	// Features & roadmap. Wraps the lib's CnFeaturesAndRoadmapPage (which has
	// no slots, so `type: "roadmap"` could not carry a third surface) and adds
	// the capability comparison. See the component header.
	FeaturesRoadmapView,
	// The deleted lens. A plain component rather than an index page: the
	// deleted rows are not in the objects endpoint the index renderer fetches
	// from, they are in OpenRegister's trash, which answers on its own door.
	DeletedCasesView,
	MailIntakeLogView,
	StoreGallery, // remote store cards — index renderer cannot address a REMOTE object
	// CaseMapView removed — see import comment above.

	// --- Lib gaps: would migrate once lib gains the missing primitive. ---
	// Processing time is a type:"dashboard" page; these are its slots.
	DtCaseTypeFilter, // header-actions slot: SLA-bearing case types only
	DtKpiWidget, // KPI row + the three guidance states
	DtChartsWidget, // donut / histogram / trend / throughput
	DtWooWidget, // Woo statutory-deadline panel
	DtAtRiskWidget, // open cases within 25% of deadline
	DtBreakdownWidget, // per-case-type performance table
	// Deadline monitoring is a type:"dashboard" page; these are its slots.
	TdCaseTypeFilter, // header-actions slot: case-type filter
	TdKpiWidget, // headline KPI tiles (CnKpiGrid + CnStatsBlock)
	TdQuarterlyWidget, // quarterly report table + CSV export
	TdAnnualWidget, // annual dwangsom audit summary
	// Process mining is a type:"dashboard" page; these are its widget slots.
	// The page owns the heading and both filters — a widget that drew its own
	// heading would be the dashboard-in-dashboard antipattern (hydra#316).
	PmCaseTypeFilter, // header-actions slot: case-type filter (pageFilters cannot bind dynamic options)
	PmKpiWidget, // headline KPI tiles (CnKpiGrid + CnStatsBlock)
	PmDwellChartWidget, // dwell time by status (CnChartWidget bar)
	PmThroughputChartWidget, // weekly throughput (CnChartWidget line)
	PmBottleneckTableWidget, // bottleneck ranking (ad-hoc row shape, no object-list leaf applies)

	// --- Anonymous-public routes (no auth, no main menu). ---
	PublicAppointmentPage,
	PublicStatusPage,
	PublicFederatedTransferPage,
	// The token-addressed advice-response page is gone. Nothing ever minted
	// the token it read, so it could never be entered; an advisory body now
	// answers through an OpenRegister access link declaring `comment` (#3817).

	// --- Leverancier-zaakportaal external supplier portal MOVED to Portaliq
	//     (ADR-046, procest#162) — see import-section comment. ---

	// --- Detail-tab components (one per case-detail cross-schema relation). ---
	CaseTasksTab, // tasks where task.case === parent.id
	// CaseDecisionsTab was retired by dossiq-decisions-to-decidiq: decisions
	// are authored in decidiq (besluitvorming leaf); the read-only
	// case-decisions widget displays the outcomes stored on the case.
	CaseDocumentsTab, // documents where document.case === parent.id

	// --- Deelzaak (sub-case) views (manifest /cases/:id/deelzaken[/...]). ---
	DeelzaakList, // sub-case list for a parent case
	DeelzaakDetail, // sub-case detail with parent breadcrumb

	// --- Mobiel-inspectie retired — see import-section comment; "Veldinspecties"
	//     is now a dashboard page surfacing the `field-inspection` leaf. ---

	// --- Case-email sidebar tab (display via leaf, compose via NC Mail draft). ---
	CaseEmailTab,
	CaseTimelineTab,

	// --- Features & Roadmap page (lib's CnFeaturesAndRoadmapView). ---
}
