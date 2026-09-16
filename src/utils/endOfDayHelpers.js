/**
 * Where an end-of-day update is written.
 *
 * ON THE THING IT IS ABOUT, always. The requirement says an update "SHALL be
 * recorded on that case", and the whole point of closing out the day is that
 * what you wrote is there tomorrow for whoever opens the case, including you.
 * A per-person diary would have been easier and would have recorded nothing
 * anybody else can find.
 *
 * A case and a task keep their notes in two different places, because an
 * engine task is not an OpenRegister object and has no register, schema or
 * object id to build the object-notes URL from. That is the same split
 * `TaskNotesLeaf` documents; this file dispatches on it rather than pretending
 * one URL serves both.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The register every dossiq case lives in. Frozen, it is a stored slug. */
export const CASE_REGISTER = 'dossiq'

/**
 * The endpoint one item's notes live on.
 *
 * @param {object} item The queue item.
 * @return {string|null} The url, or null when the item has no notes.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export function notesUrlFor(item) {
	const id = String(item?.subjectId ?? '').trim()
	if (id === '') {
		return null
	}

	if (item?.subjectType === 'task') {
		return generateUrl(
			`/apps/openregister/api/flow-tasks/${encodeURIComponent(id)}/notes`,
		)
	}

	if (item?.subjectType === 'case') {
		return generateUrl(
			`/apps/openregister/api/objects/${encodeURIComponent(CASE_REGISTER)}`
				+ `/case/${encodeURIComponent(id)}/notes`,
		)
	}

	return null
}

/**
 * Record one update on the thing it is about.
 *
 * @param {object} item The queue item.
 * @param {string} text What happened.
 * @return {Promise<boolean>} TRUE when the update was written.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export async function recordUpdateOn(item, text) {
	const message = String(text ?? '').trim()
	const url = notesUrlFor(item)
	if (message === '' || url === null) {
		return false
	}

	await axios.post(url, { message })

	return true
}
