/**
 * Which role types the Add party form offers, and in which order.
 *
 * A role type is what an organisation calls a seat on its own process, and
 * most of them are scoped to one case type. The authorised representative of
 * Awb 2:1 is not: anyone may let a representative act for them in any case, so
 * the row carrying `genericRole: gemachtigde` names no case type and belongs on
 * every list.
 *
 * 🔴 THE CASE TYPE'S OWN ROWS COME FIRST. The first question on a case is who
 * is handling it, and that is a seat this organisation named. The roles the law
 * names follow. This is the same order `CaseRoleVocabulary::vocabulary()` writes
 * onto the case schema, deliberately: two lists of the same thing in two orders
 * is how a handler learns not to trust either.
 *
 * 🔴 A GENERIC ROW IS SKIPPED WHEN THE TYPE ALREADY CLAIMS ITS KEY. A bezwaar
 * type that declares its own Gemachtigde would otherwise offer two entries with
 * the same name and nothing on screen to tell them apart. The type's own row
 * wins, because it is the one its statuses and routing rules already point at.
 *
 * 🔴 AN UNKNOWN CASE TYPE FALLS BACK TO EVERY ROW rather than to the generic
 * ones alone. Returning only the generic rows for a case whose type could not
 * be read would empty the picker on exactly the case where a handler is trying
 * to record somebody, and an empty picker reads as "this instance has no role
 * types" rather than as "we could not tell which ones apply".
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { CASE_REGISTER, CASE_SCHEMA } from './caseAccessApi.js'

/**
 * The schema the role types live in.
 */
export const ROLE_TYPE_SCHEMA = 'roleType'

/**
 * How many role types one read takes. The same page size the backend
 * vocabulary uses, so the two cannot disagree about which rows exist.
 */
export const ROLE_TYPE_PAGE_SIZE = 200

/**
 * The uuid a row came back under.
 *
 * @param {object} row The role type row.
 * @return {string} The uuid, '' when the row carries none.
 */
function idOf(row) {
	return String(row?.id || row?.['@self']?.id || row?.uuid || '')
}

/**
 * The case type a row is scoped to.
 *
 * @param {object} row The role type row.
 * @return {string} The case type uuid, '' when the row names none.
 */
function caseTypeOf(row) {
	const value = row?.caseType
	if (value && typeof value === 'object') {
		return String(value.id || value['@self']?.id || '')
	}
	return String(value || '').trim()
}

/**
 * One row as an option for the picker.
 *
 * @param {object} row The role type row.
 * @param {string} scope Whether the row is the case type's own or a generic one.
 * @return {object} The option.
 */
function optionOf(row, scope) {
	return {
		id: idOf(row),
		label: String(row?.name || '').trim() || idOf(row),
		description: String(row?.description || '').trim(),
		genericRole: String(row?.genericRole || '').trim(),
		scope,
	}
}

/**
 * The role types offered on one case, the case type's own first.
 *
 * @param {Array<object>} rows Every role type this instance holds.
 * @param {string} caseTypeId The uuid of the case's type, '' when unknown.
 * @return {Array<object>} The options, in the order the picker shows them.
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-every-case-type-offers-a-gemachtigde-role-req-role-009
 */
export function offeredRoleTypes(rows, caseTypeId) {
	const all = (Array.isArray(rows) ? rows : []).filter(
		(row) => row && idOf(row) !== '',
	)
	const wanted = String(caseTypeId || '').trim()

	if (wanted === '') {
		return all.map((row) =>
			optionOf(row, caseTypeOf(row) === '' ? 'generic' : 'caseType'),
		)
	}

	const own = all
		.filter((row) => caseTypeOf(row) === wanted)
		.map((row) => optionOf(row, 'caseType'))
	const claimed = new Set(
		own.map((option) => option.genericRole).filter((key) => key !== ''),
	)

	const generic = all
		.filter((row) => caseTypeOf(row) === '')
		.map((row) => optionOf(row, 'generic'))
		.filter(
			(option) =>
				option.genericRole === '' || !claimed.has(option.genericRole),
		)

	return [...own, ...generic]
}

/**
 * Every role type this instance holds.
 *
 * A failed read answers null rather than an empty list, for the reason the
 * party widget answers null: a picker drawn empty says this instance declares
 * no roles, which is a different and wrong answer.
 *
 * @return {Promise<Array<object>|null>} The rows, or null when they could not be read.
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-every-case-type-offers-a-gemachtigde-role-req-role-009
 */
export async function fetchRoleTypes() {
	try {
		const { data } = await axios.get(
			generateUrl(
				`/apps/openregister/api/objects/${CASE_REGISTER}/${ROLE_TYPE_SCHEMA}`,
			),
			{ params: { _limit: ROLE_TYPE_PAGE_SIZE } },
		)
		if (Array.isArray(data?.results)) {
			return data.results
		}
		return Array.isArray(data) ? data : null
	} catch {
		return null
	}
}

/**
 * The case type one case is of.
 *
 * '' covers both "the case names no type" and "the case could not be read",
 * and both mean the same thing to the caller: it cannot narrow the list, so it
 * offers every row rather than guessing.
 *
 * @param {string} caseId The case uuid.
 * @return {Promise<string>} The case type uuid, '' when there is none to read.
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-every-case-type-offers-a-gemachtigde-role-req-role-009
 */
export async function fetchCaseTypeOf(caseId) {
	const id = String(caseId || '').trim()
	if (id === '') {
		return ''
	}

	try {
		const { data } = await axios.get(
			generateUrl(
				`/apps/openregister/api/objects/${CASE_REGISTER}/${CASE_SCHEMA}/${encodeURIComponent(id)}`,
			),
		)
		return caseTypeOf(data)
	} catch {
		return ''
	}
}
