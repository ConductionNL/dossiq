/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case a dialog acts on, read once for its header.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Read a case, or null when it cannot be read.
 *
 * @param {string} caseId The case id.
 * @return {Promise<object|null>} The case object.
 * @spec openspec/specs/case-management/spec.md
 */
export async function fetchCaseSummary(caseId) {
	const id = String(caseId ?? '')
	if (id === '' || id.startsWith('@')) {
		return null
	}
	try {
		const { data } = await axios.get(generateUrl(`/apps/openregister/api/objects/dossiq/case/${encodeURIComponent(id)}`))
		return data || null
	} catch {
		return null
	}
}

/**
 * The context line above a case dialog's title: the act, then the case number.
 *
 * @param {string} act The act, already translated (e.g. "Hand over").
 * @param {object|null} kase The case, when read.
 * @return {string} The eyebrow.
 * @spec openspec/specs/case-management/spec.md
 */
export function caseEyebrow(act, kase) {
	const number = String(kase?.identifier ?? '')
	return number === '' ? act : `${act} · ${number}`
}
