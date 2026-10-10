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
 * 🔴 THE STATE IS READ OFF THE OBJECT, NOT FETCHED. `@self.watching` rides
 * every object read and every list row, so a page renders the button from
 * data it already has. There is no route that answers the state on its own.
 * An anonymous read carries no marker at all, because there is no "you" to
 * answer for, so an absent flag reads as not following.
 *
 * 🔴 THE COUNT IS TOLD TO AN EDITOR ONLY. `@self.watcherCount` is attached on
 * the render path for a reader who may update the case, and omitted for
 * everybody else. So an absent count means "not your business", never "nobody
 * follows this", and `watcherCountOf()` answers null rather than zero.
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
 * @spec openspec/changes/one-follow-control/specs/case-management/spec.md
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
 * Turn the notifications of your follow on or off, and keep following.
 *
 * A follow with notifications off is what a favourite used to be
 * (openregister `merge-follow-and-favourites`): the case stays under
 * Following and on the tile, and nothing about it reaches your notifications.
 *
 * @param {string}  id       The object uuid.
 * @param {boolean} notify   TRUE to hear about changes, FALSE for a quiet follow.
 * @param {string}  register The register slug.
 * @param {string}  schema   The schema slug.
 * @return {Promise<object>} The subscription row the write answered.
 *
 * @spec openspec/changes/one-follow-control/specs/case-management/spec.md
 */
export async function setNotify(
	id,
	notify,
	register = CASE_REGISTER,
	schema = CASE_SCHEMA,
) {
	const { data } = await axios.put(`${objectUrl(id, register, schema)}/watch`, {
		notify: notify === true,
	})

	return data ?? {}
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
 * The people following an object.
 *
 * Needs `update` on the object. A reader without it gets 403, which the caller
 * has to tell apart from an empty list.
 *
 * @param {string} id       The object uuid.
 * @param {string} register The register slug.
 * @param {string} schema   The schema slug.
 * @return {Promise<Array<object>>} One row per follower.
 *
 * @spec openspec/changes/case-followers/specs/case-management/spec.md
 */
export async function listFollowers(
	id,
	register = CASE_REGISTER,
	schema = CASE_SCHEMA,
) {
	const { data } = await axios.get(`${objectUrl(id, register, schema)}/watchers`)

	return Array.isArray(data?.results) ? data.results : []
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
 * Whether this reader's follow of a row sends notifications.
 *
 * `@self.watchNotify` rides every read next to `@self.watching`. An older
 * OpenRegister sends no switch, and every follow then notified, so an absent
 * switch reads as on.
 *
 * @param {object} row The object as the store holds it.
 * @return {boolean} TRUE when the follow notifies.
 *
 * @spec openspec/changes/one-follow-control/specs/case-management/spec.md
 */
export function notifiesOf(row) {
	return row?.['@self']?.watchNotify !== false
}

/**
 * How many people follow a row the page already fetched.
 *
 * `@self.watcherCount` is attached for a reader who may update the object and
 * omitted for everybody else, so an absent count is "not told to you" and not
 * "nobody". Null says that, where zero would be a claim about the audience.
 *
 * @param {object} row The object as the store holds it.
 * @return {number|null} The count, or null when it was not told to this reader.
 *
 * @spec openspec/changes/case-followers/specs/case-management/spec.md
 */
export function followerCountOf(row) {
	const count = row?.['@self']?.watcherCount

	return typeof count === 'number' ? count : null
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
