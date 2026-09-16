/**
 * The per-user star on a dossiq object, read from and written to OpenRegister.
 *
 * OpenRegister owns the star (`favourites-and-recent`, openregister#3766). It
 * lives in `openregister_favourites`, keyed by (user, object uuid), and never
 * touches the object row: starring cuts no version and writes no audit entry,
 * so a colleague reading the case cannot tell you did it. dossiq holds none of
 * it, which is why this file is a thin client over two verbs and not a store.
 *
 *   PUT    /apps/openregister/api/objects/{register}/{schema}/{id}/favourite
 *   DELETE /apps/openregister/api/objects/{register}/{schema}/{id}/favourite
 *
 * 🔴 THERE IS NO THIRD ROUTE THAT READS THE STATE, AND THAT IS DELIBERATE.
 * Every object read already carries `@self.favourite` for the calling user, on
 * a single-object read and on every list row, so a page renders the star from
 * data it has rather than firing a call per row. `isFavourite()` below is that
 * read. An anonymous read carries no `@self.favourite` at all, so an absent
 * flag is read as not starred: there is no "you" to answer for, and a page
 * that painted every row starred would be lying in the expensive direction.
 *
 * 🔴 BOTH VERBS ARE IDEMPOTENT. Starring something already starred answers the
 * same as starring it once, and unstarring something never starred likewise.
 * The caller asked for a state, and after the call that state holds. So a
 * double click is not an error to handle, and a retry is safe.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-number-and-favourites/specs/case-management/spec.md
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
 * The favourite endpoint of one object.
 *
 * @param {string} id       The object uuid.
 * @param {string} register The register slug.
 * @param {string} schema   The schema slug.
 * @return {string} The absolute url.
 */
function favouriteUrl(id, register = CASE_REGISTER, schema = CASE_SCHEMA) {
	return generateUrl(
		`/apps/openregister/api/objects/${encodeURIComponent(register)}`
			+ `/${encodeURIComponent(schema)}/${encodeURIComponent(id)}/favourite`,
	)
}

/**
 * Star an object for the signed-in user.
 *
 * @param {string} id       The object uuid.
 * @param {string} register The register slug.
 * @param {string} schema   The schema slug.
 * @return {Promise<{favourite: boolean}>} What the write answered.
 *
 * @spec openspec/changes/case-number-and-favourites/specs/case-management/spec.md
 */
export async function star(id, register = CASE_REGISTER, schema = CASE_SCHEMA) {
	const { data } = await axios.put(favouriteUrl(id, register, schema))

	return { favourite: data?.favourite !== false }
}

/**
 * Take the signed-in user's star off an object.
 *
 * It stays starred for everybody else: the row this removes is nobody's but
 * the caller's.
 *
 * @param {string} id       The object uuid.
 * @param {string} register The register slug.
 * @param {string} schema   The schema slug.
 * @return {Promise<{favourite: boolean}>} What the write answered.
 *
 * @spec openspec/changes/case-number-and-favourites/specs/case-management/spec.md
 */
export async function unstar(id, register = CASE_REGISTER, schema = CASE_SCHEMA) {
	const { data } = await axios.delete(favouriteUrl(id, register, schema))

	return { favourite: data?.favourite === true }
}

/**
 * Star or unstar, whichever the wanted state asks for.
 *
 * @param {string}  id       The object uuid.
 * @param {boolean} wanted   TRUE to star, FALSE to unstar.
 * @param {string}  register The register slug.
 * @param {string}  schema   The schema slug.
 * @return {Promise<{favourite: boolean}>} What the write answered.
 *
 * @spec openspec/changes/case-number-and-favourites/specs/case-management/spec.md
 */
export async function setFavourite(
	id,
	wanted,
	register = CASE_REGISTER,
	schema = CASE_SCHEMA,
) {
	if (wanted === true) {
		return star(id, register, schema)
	}

	return unstar(id, register, schema)
}

/**
 * Whether a row the page already fetched is starred by this reader.
 *
 * `@self.favourite` rides every read and every list row, and is OMITTED for an
 * anonymous read where there is no "you" to answer for. So an absent flag
 * reads as not starred rather than as starred.
 *
 * @param {object} row The object as the store holds it.
 * @return {boolean} TRUE when this reader has starred it.
 *
 * @spec openspec/changes/case-number-and-favourites/specs/case-management/spec.md
 */
export function isFavourite(row) {
	return row?.['@self']?.favourite === true
}

/**
 * The uuid of an object as a list row or a detail payload carries it.
 *
 * A row built from a list read carries `id`; one read through `@self` carries
 * it there. Asking both is what lets one handler serve a row action and a
 * detail page.
 *
 * @param {object} row The object as the store holds it.
 * @return {string} The uuid, or the empty string when the row carries none.
 *
 * @spec openspec/changes/case-number-and-favourites/specs/case-management/spec.md
 */
export function objectIdOf(row) {
	return String(row?.id ?? row?.['@self']?.id ?? '')
}
