/**
 * AVG verwerkingenlogging API wrapper (avg-verwerkingenlogging, thin consumer).
 *
 * Thin axios client over OPENREGISTER's AVG endpoints — procest ships no
 * processing-log endpoints of its own (ADR-022 / OR-PA-9). OpenRegister
 * enforces all authorisation (admin or delegated FG group, fail-closed) and
 * tenant scoping server-side; this client only shapes requests and unwraps
 * responses. External audit tooling consumes the same OR endpoints directly.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/avg-verwerkingenlogging/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const orBase = (suffix = '') => generateUrl(`/apps/openregister${suffix}`)

/**
 * Fallback activity code OR attributes unmapped processing to (OR-PA-4).
 */
export const FALLBACK_ACTIVITY_CODE = 'niet-geclassificeerde-verwerking'

/**
 * List the processing-activity catalogue (all statuses) from OR's
 * verwerkingsregister. 403 for non-FG/non-admin users (OR-PA-8).
 *
 * @return {Promise<Array>} Activity records with code/naam/status/rechtsgrond.
 * @spec openspec/specs/avg-verwerkingenlogging/spec.md
 */
export async function listVerwerkingsactiviteiten() {
	const { data } = await axios.get(orBase('/api/avg/verwerkingsactiviteiten'))
	return (data && (data.results || data)) || []
}

/**
 * Count processing-log entries attributed to an activity, scoped to a
 * procest register (OR filters + tenant scoping are server-side).
 *
 * @param {object} options Filter options.
 * @param {string} options.activity Activity uuid to filter on.
 * @param {string} [options.register] Register id to scope to.
 * @return {Promise<number>} Entry count (page-capped by OR's limit).
 * @spec openspec/specs/avg-verwerkingenlogging/spec.md
 */
export async function countVerwerkingen({ activity, register } = {}) {
	const params = {}
	if (activity) {
		params.activity = activity
	}
	if (register) {
		params.register = register
	}
	const { data } = await axios.get(orBase('/api/avg/verwerkingen'), { params })
	return (data && data.count) || 0
}

/**
 * Per-betrokkene inzageverzoek extract (OR-PA-7): every logged read of the
 * subject's data, produced and access-logged by OpenRegister.
 *
 * @param {object} subject Subject identifier.
 * @param {string} subject.idType Subject id type (e.g. BSN, contact).
 * @param {string} subject.idValue Subject id value.
 * @param {string} [subject.from] ISO lower bound.
 * @param {string} [subject.to] ISO upper bound.
 * @return {Promise<object>} The extract envelope ({subject, period, count, reads}).
 * @spec openspec/specs/avg-verwerkingenlogging/spec.md
 */
export async function fetchBetrokkeneExtract({ idType, idValue, from, to }) {
	const params = { subjectIdType: idType, subjectIdValue: idValue }
	if (from) {
		params.from = from
	}
	if (to) {
		params.to = to
	}
	const { data } = await axios.get(orBase('/api/avg/verwerkingen/betrokkene'), {
		params,
	})
	return data
}
