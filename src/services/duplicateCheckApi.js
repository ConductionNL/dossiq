/**
 * Is there already a case like this one, asked before the case exists.
 *
 * A thin client over OpenRegister's `dedup-check`, which writes nothing and
 * scores the candidate with the same function the nightly duplicate sweep uses.
 * The warning a handler sees and the pair the sweep finds therefore agree,
 * which is the whole reason nothing is compared here (ADR-022).
 *
 * 🔴 AN EMPTY MATCH LIST IS NOT ALWAYS "WE LOOKED AND FOUND NOTHING". It is
 * also what a failed call answers, and the two readings lead to opposite acts:
 * one says file the case, the other says ask again. So the answer carries
 * `checked`, and a caller that gets `checked: false` must not draw the panel as
 * an all clear. Failing the other way, blocking the create when the endpoint is
 * unreachable, would stop an intake desk filing cases because one read timed
 * out, and the write path refuses a real duplicate anyway.
 *
 * The matches carry a uuid and a score, never the matched case. Naming the case
 * is a second, RBAC-scoped read, so a handler who may not see a case never
 * learns its title from a warning about it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The OpenRegister register the case schema lives in. Frozen slug. */
const REGISTER = 'dossiq'

/** The schema carrying the dedup rules. */
const SCHEMA = 'case'

/**
 * The stored cases that look like the one being filed.
 *
 * @param {object} candidate The unsaved case, as the form holds it.
 * @return {Promise<{matches: Array<object>, total: number, threshold: number, checked: boolean}>} What the platform answered.
 *
 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
 */
export async function checkForDuplicates(candidate) {
	const empty = { matches: [], total: 0, threshold: 0, checked: false }
	if (!candidate || typeof candidate !== 'object') {
		return empty
	}

	try {
		const response = await axios.post(
			generateUrl(
				`/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/dedup-check`,
			),
			candidate,
		)
		const data = response?.data || {}
		const matches = Array.isArray(data.matches) ? data.matches : []

		return {
			matches,
			total: Number.isFinite(data.total) ? data.total : matches.length,
			threshold: Number.isFinite(data.threshold) ? data.threshold : 0,
			checked: true,
		}
	} catch {
		return empty
	}
}

/**
 * The cases behind a list of match uuids, so the panel can name them.
 *
 * Read one by one rather than in a batch: a handler may be allowed to see one
 * of the matches and not the other, and a batch read that 403s on the second
 * would hide the first as well. A case that cannot be read is left out of the
 * answer, so the panel shows what it can name and counts the rest.
 *
 * @param {Array<string>} uuids The match uuids.
 * @return {Promise<Array<object>>} The cases that could be read.
 *
 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
 */
export async function fetchMatchedCases(uuids) {
	const ids = Array.isArray(uuids) ? uuids.filter(Boolean) : []
	if (ids.length === 0) {
		return []
	}

	const reads = ids.map(async (id) => {
		try {
			const response = await axios.get(
				generateUrl(
					`/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${encodeURIComponent(id)}`,
				),
			)
			return response?.data || null
		} catch {
			return null
		}
	})

	return (await Promise.all(reads)).filter(Boolean)
}
