// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Turning the three answers the backend gives into the words on the screen.
//
// 🔑 UNTESTED IS NOT FAILED AND SHIPPED-AND-CHANGED IS NOT OURS. Both
// distinctions are the whole point of the screens that use this file, and both
// are easy to flatten into a boolean by accident: a red cross on a connection
// nobody probed sends somebody debugging a working integration, and an object
// the administrator edited reading as "ours" hides that an upgrade is waiting
// for it.

import { translate as t } from '@nextcloud/l10n'

/**
 * The three states a seeded object can be in, plus the one for a row somebody
 * deleted here.
 *
 * @type {object}
 */
export const SHIPPED_STATES = {
	shipped: 'shipped',
	changed: 'changed',
	local: 'local',
	removed: 'removed',
}

/**
 * The label of each shipped state that is not the administrator's own.
 *
 * @type {Map<string, function(): string>}
 */
const SHIPPED_LABELS = new Map([
	[SHIPPED_STATES.shipped, () => t('dossiq', 'Shipped, unchanged')],
	[SHIPPED_STATES.changed, () => t('dossiq', 'Shipped, changed here')],
	[SHIPPED_STATES.removed, () => t('dossiq', 'Shipped, removed here')],
])

/**
 * What an administrator reads beside one seeded object.
 *
 * @param {object} row One row from GET /api/starter/shipped/{schema}.
 * @return {string} The label.
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
export function shippedLabel(row) {
	const state = row && row.state ? row.state : SHIPPED_STATES.local
	const label = SHIPPED_LABELS.get(state)
	return label ? label() : t('dossiq', 'Yours')
}

/**
 * Whether a newer shipped version is waiting for this object.
 *
 * @param {object} row One row from GET /api/starter/shipped/{schema}.
 * @return {boolean} True when the newer version can be adopted.
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
export function hasUpdate(row) {
	return Boolean(
		row && row.updateAvailable && row.state !== SHIPPED_STATES.removed,
	)
}

/**
 * Whether adopting this object would overwrite something somebody typed.
 *
 * The adopt call refuses a changed object unless it is told the loss is
 * accepted, so the screen has to ask first rather than find out afterwards.
 *
 * @param {object} row One row from GET /api/starter/shipped/{schema}.
 * @return {boolean} True when the administrator must be warned.
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
export function adoptionLosesLocalChange(row) {
	return Boolean(row && row.state === SHIPPED_STATES.changed)
}

/**
 * What a connection reads as, given its last test.
 *
 * @param {object} result The result of POST /api/connections/.../test, or null
 *                        when nobody has pressed the button.
 * @return {object} `{state, label, type, measuredAt}` for the note card.
 * @spec openspec/changes/starter-content-and-templates/specs/admin-settings/spec.md
 */
export function connectionLabel(result) {
	if (isUntested(result)) {
		return {
			state: 'not_tested',
			label: t('dossiq', 'Not tested'),
			type: 'warning',
			measuredAt: '',
		}
	}

	if (result.state === 'reachable') {
		return reachableConnectionLabel(result)
	}
	return failedConnectionLabel(result)
}

/**
 * Whether nobody has tested the connection yet.
 *
 * @param {object|null} result The last test result, or null.
 * @return {boolean} True when there is no result with a tested state.
 */
function isUntested(result) {
	return !result || !result.state || result.state === 'not_tested'
}

/**
 * @param {object} result A test result whose endpoint answered.
 * @return {object} `{state, label, type, measuredAt}` for the note card.
 */
function reachableConnectionLabel(result) {
	return {
		state: 'reachable',
		label: t('dossiq', 'Answered {status}', { status: result.status }),
		type: 'success',
		measuredAt: result.measuredAt || '',
	}
}

/**
 * @param {object} result A test result whose endpoint did not answer.
 * @return {object} `{state, label, type, measuredAt}` for the note card.
 */
function failedConnectionLabel(result) {
	return {
		state: 'failed',
		label: result.reason || t('dossiq', 'The endpoint did not answer'),
		type: 'error',
		measuredAt: result.measuredAt || '',
	}
}

export default {
	SHIPPED_STATES,
	shippedLabel,
	hasUpdate,
	adoptionLosesLocalChange,
	connectionLabel,
}
