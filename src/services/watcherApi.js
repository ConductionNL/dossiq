/**
 * Following a dossiq case, read from and written to OpenRegister.
 *
 * OpenRegister owns the subscription (`object-watchers`). A row lives in
 * `openregister_watchers`, keyed by (user, object uuid), and never touches the
 * object: following cuts no version and writes no audit entry. dossiq holds
 * none of it, which is why this file is a thin client over three routes and
 * not a store.
 *
 *   PUT    /apps/openregister/api/objects/{register}/{schema}/{id}/watch
 *   DELETE /apps/openregister/api/objects/{register}/{schema}/{id}/watch
 *   GET    /apps/openregister/api/objects/{register}/{schema}/{id}/watchers
 *
 * 🔴 A FAVOURITE IS A FOLLOW WITH NOTIFICATIONS OFF. Following and starring
 * were two features with two tables; since openregister
 * `merge-follow-and-favourites` (Ruben, 9 October, reviewing DqMijnWerk) they
 * are one. Every follow carries a notifications switch, `PUT .../watch` with
 * `{"notify": false}` turns it off and keeps the follow, and the star is gone
 * from dossiq (`one-follow-control`). The follower list stays visible to the
 * people who may edit the case, quiet follows included.
 *
 * 🔴 THE CASE PAGE'S BUTTON IS NOT HERE. The Follow control beside the case
 * title, with its bell and its count, is nextcloud-vue's CnFollowToggle,
 * which CnDetailPage draws for every object that carries `@self.watching`
 * (`one-follow-control-in-the-header`). dossiq's own strip drew a second one
 * that never agreed with it, and is gone. What stays here is the row action
 * on the lists and the Followers section on the People tab.
 *
 * 🔴 THE STATE IS READ OFF THE OBJECT, NOT FETCHED. `@self.watching` rides
 * every object read and every list row, so a row renders from data it already
 * has. There is no route that answers the state on its own. An anonymous read
 * carries no marker at all, because there is no "you" to answer for, so an
 * absent flag reads as not following.
 *
 * 🔴 LISTING THE FOLLOWERS NEEDS `update` ON THE CASE. A reader without it is
 * refused with 403, which is a different answer from a case nobody follows.
 * `listFollowers()` hands the refusal back so the caller can say which of the
 * two it is.
 *
 * Both write verbs are idempotent: the caller asked for a state, and after the
 * call that state holds. A double press is not an error, and a retry is safe.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-followers/specs/case-management/spec.md
 * @spec openspec/specs/case-management/spec.md#requirement-you-follow-a-case-you-do-not-own-req-cm-40
 * @spec openspec/specs/case-management/spec.md#requirement-the-case-page-shows-one-follow-control-req-cm-40a
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
 * The base path of one object.
 *
 * @param {string} id       The object uuid.
 * @param {string} register The register slug.
 * @param {string} schema   The schema slug.
 * @return {string} The absolute url, with no sub-resource.
 */
function objectUrl(id, register, schema) {
	return generateUrl(
		`/apps/openregister/api/objects/${encodeURIComponent(register)}`
			+ `/${encodeURIComponent(schema)}/${encodeURIComponent(id)}`,
	)
}

/**
 * Follow an object as the signed-in user.
 *
 * @param {string} id       The object uuid.
 * @param {string} register The register slug.
 * @param {string} schema   The schema slug.
 * @return {Promise<object>} The subscription row the write answered.
 *
 * @spec openspec/changes/case-followers/specs/case-management/spec.md
 */
export async function follow(id, register = CASE_REGISTER, schema = CASE_SCHEMA) {
	const { data } = await axios.put(`${objectUrl(id, register, schema)}/watch`)

	return data ?? {}
}

/**
 * Stop following an object.
 *
 * Everybody else goes on following it: the row this removes is nobody's but
 * the caller's.
 *
 * @param {string} id       The object uuid.
 * @param {string} register The register slug.
 * @param {string} schema   The schema slug.
 * @return {Promise<void>}
 *
 * @spec openspec/changes/case-followers/specs/case-management/spec.md
 */
export async function unfollow(id, register = CASE_REGISTER, schema = CASE_SCHEMA) {
	await axios.delete(`${objectUrl(id, register, schema)}/watch`)
}

/**
 * Follow or unfollow, whichever the wanted state asks for.
 *
 * @param {string}  id       The object uuid.
 * @param {boolean} wanted   TRUE to follow, FALSE to stop.
 * @param {string}  register The register slug.
 * @param {string}  schema   The schema slug.
 * @return {Promise<void>}
 *
 * @spec openspec/changes/case-followers/specs/case-management/spec.md
 */
export async function setFollowing(
	id,
	wanted,
	register = CASE_REGISTER,
	schema = CASE_SCHEMA,
) {
	if (wanted === true) {
		await follow(id, register, schema)
		return
	}

	await unfollow(id, register, schema)
}

/**
 * Whether a row the page already fetched is followed by this reader.
 *
 * `@self.watching` rides every read and every list row, and is OMITTED for an
 * anonymous read. So an absent marker reads as not following.
 *
 * @param {object} row The object as the store holds it.
 * @return {boolean} TRUE when this reader follows it.
 *
 * @spec openspec/changes/case-followers/specs/case-management/spec.md
 */
export function isFollowing(row) {
	return row?.['@self']?.watching === true
}

/**
 * The uuid of an object as a list row or a detail payload carries it.
 *
 * A row built from a list read carries `id`; one read through `@self` carries
 * it there. Asking both is what lets one handler serve a row action and a
 * detail page.
 *
 * @param {object} row The object as the store holds it.
 * @return {string} The uuid, or the empty string.
 *
 * @spec openspec/changes/case-followers/specs/case-management/spec.md
 */
export function objectIdOf(row) {
	return String(row?.id ?? row?.['@self']?.id ?? '')
}
