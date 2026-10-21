// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Turning a connection test's answer into the words on the screen.
//
// 🔑 UNTESTED IS NOT FAILED. A red cross on a connection nobody probed sends
// somebody debugging a working integration.

import { translate as t } from '@nextcloud/l10n'

/**
 * What a connection reads as, given its last test.
 *
 * @param {object} result The result of POST /api/connections/.../test, or null
 *                        when nobody has pressed the button.
 * @return {object} `{state, label, type, measuredAt}` for the note card.
 * @spec openspec/changes/starter-content-and-templates/specs/admin-settings/spec.md
 */
export function connectionLabel(result) {
	if (!result || !result.state || result.state === 'not_tested') {
		return {
			state: 'not_tested',
			label: t('dossiq', 'Not tested'),
			type: 'warning',
			measuredAt: '',
		}
	}

	if (result.state === 'reachable') {
		return {
			state: 'reachable',
			label: t('dossiq', 'Answered {status}', { status: result.status }),
			type: 'success',
			measuredAt: result.measuredAt || '',
		}
	}

	return {
		state: 'failed',
		label: result.reason || t('dossiq', 'The endpoint did not answer'),
		type: 'error',
		measuredAt: result.measuredAt || '',
	}
}

export default {
	connectionLabel,
}
