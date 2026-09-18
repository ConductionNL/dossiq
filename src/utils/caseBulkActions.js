// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The Cases index's bulk-action handlers, named from the manifest's
// `config.bulkActions[].handler` and registered in registry.js as
// `kind: 'handler'` entries.
//
// Their own module, like caseClaim.js / caseFavourite.js / caseUnread.js
// beside them, so a unit test can reach a handler without importing every page
// the registry mounts.
//
// @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md

import { createApp } from 'vue'
import BulkTransitionDialog from '../dialogs/BulkTransitionDialog.vue'
import ReassignSelectionDialog from '../dialogs/ReassignSelectionDialog.vue'
import { countMatchingCases } from '../services/bulkJobApi.js'
import { readLocationFilters } from './selectionScope.js'

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
 * Bulk-action handler for the Cases index: reassign the selected cases.
 *
 * CnIndexPage calls a registered handler with `{ actionId, selectedIds, count }`,
 * so the SELECTION arrives as an argument. That matters: a handler that went and
 * re-read the selection itself would be one re-render away from acting on a
 * different set than the user saw highlighted.
 *
 * The dialog is mounted here rather than declared in the manifest because the
 * library's declarative modal path emits `open-modal` and nothing consumes it.
 *
 * @param {{actionId: string, selectedIds: Array<string>, count: number}} scope The selection.
 * @return {void}
 */
export async function reassignSelection({ selectedIds }) {
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
export function transitionSelection({ selectedIds }) {
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
export function suspendSelection({ selectedIds }) {
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
export function resumeSelection({ selectedIds }) {
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
export function extendTermSelection({ selectedIds }) {
	openBulkDialog('extend', selectedIds)
}
