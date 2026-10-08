// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import {
	resolveStructureProfile,
	STRUCTURE_SETTING,
} from '../utils/structureProfile.js'

/**
 * Save which structure the app shows.
 *
 * It goes through dossiq's own settings write, which carries
 * `#[AuthorizedAdminSetting]`, and it names the key from
 * `utils/structureProfile.js` so the admin tab, the boot code and the PHP side
 * cannot spell it three ways.
 *
 * `fetch` resolves on a 403 as readily as on a 200, so the status is checked
 * here: a save an administrator was not allowed to make must not read as done.
 *
 * @param {string} structure `simple` or `full`.
 * @param {{ url: string, requestToken: string, fetchImpl?: typeof fetch }} options
 *   Where to post, the CSRF token, and the fetch to use (a test passes its own).
 * @return {Promise<string>} The structure the server stored.
 *
 * @spec openspec/changes/simple-structure-profile/specs/nav-dedup-and-grouping/spec.md#REQ-PNDG-007
 */
export async function saveMenuStructure(
	structure,
	{ url, requestToken, fetchImpl },
) {
	const send = fetchImpl || fetch
	const wanted = resolveStructureProfile(structure)
	const response = await send(url, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			requesttoken: requestToken,
		},
		body: JSON.stringify({ [STRUCTURE_SETTING]: wanted }),
	})
	if (!response.ok) {
		throw new Error(`settings write answered ${response.status}`)
	}
	const body = await response.json()
	const stored = body?.config?.[STRUCTURE_SETTING]
	if (stored !== wanted) {
		// The endpoint answers success for a key it does not know. Reading the
		// stored value back is the only way to tell a save from a no-op.
		throw new Error('settings write did not store the structure')
	}
	return wanted
}
