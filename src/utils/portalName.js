/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { loadState } from '@nextcloud/initial-state'

/**
 * The resident portal's name portaliq holds, from the page's `config` state.
 *
 * @return {string} The name, or '' when none is set.
 * @spec openspec/specs/portal-channel-label/spec.md#requirement-the-portal-channel-is-named-after-the-portal
 */
export function portalName() {
	try {
		return String(loadState('dossiq', 'config', {})?.portalName ?? '').trim()
	} catch {
		return ''
	}
}
