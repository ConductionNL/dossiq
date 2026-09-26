/**
 * Per-user read state for a dossiq object, read from OpenRegister.
 *
 * OpenRegister owns the read state and everything that clears it
 * (`object-read-state`, openregister#3734). Unread is the ABSENCE of a row in
 * `openregister_object_read_state`: opening an object writes yours, a
 * substantive change drops everyone else's, and marking something unread
 * deletes your own. dossiq holds none of it, which is why this file is a thin
 * client over three endpoints and not a store.
 *
 *   GET    /apps/openregister/api/objects/{register}/{schema}/{id}/read-state
 *   PUT    /apps/openregister/api/objects/{register}/{schema}/{id}/read-state
 *   DELETE /apps/openregister/api/objects/{register}/{schema}/{id}/read-state
 *
 * 🔴 THE PUT IS ALSO WHAT EMPTIES THE BELL. Marking an object read clears the
 * notifications that were about it, and a PUT carrying `subResource` clears
 * only that tab's notices. So "opening the work clears the notice" needs no
 * second call and no dismissal gesture: there is exactly one write, and a
 * dossiq-side dismissal beside it would be a second source of truth for
 * whether a handler has dealt with something.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The register every dossiq case lives in.
 *
 * Frozen: an OpenRegister register slug is written into stored data, so it did
 * not move with the app-id rename.
 */
export const CASE_REGISTER = 'dossiq'

/** The schema a case is an object of. */
export const CASE_SCHEMA = 'case'

/**
 * The read-state endpoint of one object.
 *
 * @param {string} id       The object uuid.
 * @param {string} register The register slug.
 * @param {string} schema   The schema slug.
 * @return {string} The absolute url.
 */
function readStateUrl(id, register = CASE_REGISTER, schema = CASE_SCHEMA) {
	return generateUrl(
		`/apps/openregister/api/objects/${encodeURIComponent(register)}`
			+ `/${encodeURIComponent(schema)}/${encodeURIComponent(id)}/read-state`,
	)
}

/**
 * What this reader has seen of one object.
 *
 * @param {string} id       The object uuid.
 * @param {string} register The register slug.
 * @param {string} schema   The schema slug.
 * @return {Promise<{unread: boolean, lastSeenAt: (string|null), subSeen: object, unreadCounts: object}>} The state.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */
export async function fetchReadState(
	id,
	register = CASE_REGISTER,
	schema = CASE_SCHEMA,
) {
	const { data } = await axios.get(readStateUrl(id, register, schema))

	return {
		unread: data?.unread === true,
		lastSeenAt: data?.lastSeenAt ?? null,
		subSeen: data?.subSeen ?? {},
		unreadCounts: data?.unreadCounts ?? {},
	}
}

/**
 * Record that this reader has now seen the object, or one of its tabs.
 *
 * @param {string}      id          The object uuid.
 * @param {string|null} subResource The tab that was opened, or null for the whole object.
 * @param {string}      register    The register slug.
 * @param {string}      schema      The schema slug.
 * @return {Promise<{unread: boolean, notificationsCleared: number}>} What the write answered.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */
export async function markRead(
	id,
	subResource = null,
	register = CASE_REGISTER,
	schema = CASE_SCHEMA,
) {
	const body = subResource ? { subResource } : {}
	const { data } = await axios.put(readStateUrl(id, register, schema), body)

	return {
		unread: data?.unread === true,
		notificationsCleared: Number(data?.notificationsCleared ?? 0),
	}
}

/**
 * Put the object back to unread, for this reader only.
 *
 * A real act and not a repair: a handler opens a case, finds they cannot deal
 * with it now, and wants it to look untouched in the list again. There is no
 * administrator override on the other side of this call, because somebody who
 * could mark an object read for you could make your badge lie to you.
 *
 * @param {string} id       The object uuid.
 * @param {string} register The register slug.
 * @param {string} schema   The schema slug.
 * @return {Promise<{unread: boolean}>} What the write answered.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */
export async function markUnread(
	id,
	register = CASE_REGISTER,
	schema = CASE_SCHEMA,
) {
	const { data } = await axios.delete(readStateUrl(id, register, schema))

	return { unread: data?.unread !== false }
}

/**
 * The unread counts of one object's tabs, as a plain map.
 *
 * Reads `@self.unreadCounts` off a row the list already fetched when it is
 * there, and falls back to the read-state endpoint otherwise. A row carries it
 * only on a single-object read, so a list row will fall through; that is the
 * point of asking the row first rather than firing a call per tab.
 *
 * @param {object} row The object as the store holds it.
 * @return {object|null} The counts, or null when the row does not carry them.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */
export function unreadCountsOf(row) {
	const counts = row?.['@self']?.unreadCounts ?? null

	return counts && typeof counts === 'object' ? counts : null
}

/**
 * Whether a row the list fetched is unread for this reader.
 *
 * `@self.unread` is attached per reader and OMITTED ENTIRELY for an anonymous
 * read, where there is no "you" to answer for. So an absent flag is read as
 * read rather than as unread: an anonymous or unattached row must not paint
 * the whole list in bold.
 *
 * @param {object} row The object as the store holds it.
 * @return {boolean} TRUE when the row is unread for this reader.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */
export function isUnread(row) {
	return row?.['@self']?.unread === true
}
