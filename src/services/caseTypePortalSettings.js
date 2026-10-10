/**
 * What a case type opens to its applicant in the portal, read and written.
 *
 * The case type editor's Portal section draws a form; this module turns the
 * stored case type into that form's state and the state back into the four
 * properties portaliq reads (`portalWritable`, `portalAmendmentWindow`,
 * `portalDocumentWindow`, `portalWithdrawal`, design D3 of
 * portal-citizen-writes-on-the-case). Kept pure apart from the one PATCH, so
 * the shapes are tested without mounting anything.
 *
 * 🔴 NOTHING IS WRITTEN AS NULL. The four properties are typed `object` and
 * `array` in the register fragment, and OpenRegister refuses a null where the
 * schema says object. A closed window is an empty list of open statuses, and
 * a withdrawal that is switched off is an empty object: portaliq reads both as
 * "nothing is open", which is the safe state.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The fields a case type may open to its applicant: the ceiling of the
 * `amendCase` action (design D1). Whatever a case type opens, portaliq narrows
 * it to this list, so offering more here would be a checkbox that does nothing.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
export const WRITABLE_CEILING = ['description']

/**
 * The portal audiences `amendCase` is served to (design D1).
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
export const PORTAL_AUDIENCES = ['client', 'citizen', 'supplier']

/**
 * The ids in a list, trimmed, without blanks or repeats.
 *
 * @param {Array<string|object>|undefined} value A list of ids or rows.
 * @return {string[]} The ids.
 * @spec openspec/specs/portal-contribution/spec.md
 */
function ids(value) {
	if (!Array.isArray(value)) {
		return []
	}
	const out = []
	for (const entry of value) {
		const id =
			typeof entry === 'string' ? entry.trim() : String(entry?.id ?? '').trim()
		if (id !== '' && !out.includes(id)) {
			out.push(id)
		}
	}
	return out
}

/**
 * A string, trimmed, or the empty string.
 *
 * @param {string|undefined} value The value.
 * @return {string} The text.
 * @spec openspec/specs/portal-contribution/spec.md
 */
function text(value) {
	return typeof value === 'string' ? value.trim() : ''
}

/**
 * A window block as form state.
 *
 * @param {object|undefined} block The stored window.
 * @return {{openStatuses: string[], closedReason: string}} The state.
 * @spec openspec/specs/portal-contribution/spec.md
 */
function windowState(block) {
	return {
		openStatuses: ids(block?.openStatuses),
		closedReason: text(block?.closedReason),
	}
}

/**
 * The form state for a stored case type.
 *
 * @param {object} caseType The case type as OpenRegister answers it.
 * @return {object} The state: `writable` per ceiling field, `amendment`, `documents`, `withdrawal`.
 * @spec openspec/specs/portal-contribution/spec.md
 */
export function portalStateFrom(caseType = {}) {
	const stored = Array.isArray(caseType?.portalWritable)
		? caseType.portalWritable
		: []
	const writable = {}
	for (const field of WRITABLE_CEILING) {
		const entry = stored.find((row) => row?.field === field)
		writable[field] = {
			enabled: entry !== undefined,
			openStatuses: ids(entry?.openStatuses),
			closedReason: text(entry?.closedReason),
			audiences:
				entry && ids(entry.audiences).length > 0
					? ids(entry.audiences)
					: [...PORTAL_AUDIENCES],
		}
	}

	const withdrawal = caseType?.portalWithdrawal ?? {}

	return {
		writable,
		amendment: windowState(caseType?.portalAmendmentWindow),
		documents: windowState(caseType?.portalDocumentWindow),
		withdrawal: {
			enabled: text(withdrawal.targetStatus) !== '',
			openStatuses: ids(withdrawal.openStatuses),
			targetStatus: text(withdrawal.targetStatus),
			closedReason: text(withdrawal.closedReason),
			confirmText: text(withdrawal.confirmText),
		},
	}
}

/**
 * The four properties to write for a form state.
 *
 * @param {object} state The state `portalStateFrom` returns, after editing.
 * @return {object} The body of the PATCH.
 * @spec openspec/specs/portal-contribution/spec.md
 */
export function portalPayload(state) {
	const portalWritable = []
	for (const field of WRITABLE_CEILING) {
		const entry = state?.writable?.[field]
		if (!entry?.enabled) {
			continue
		}
		const row = {
			field,
			audiences:
				ids(entry.audiences).length > 0
					? ids(entry.audiences)
					: [...PORTAL_AUDIENCES],
			openStatuses: ids(entry.openStatuses),
		}
		if (text(entry.closedReason) !== '') {
			row.closedReason = text(entry.closedReason)
		}
		portalWritable.push(row)
	}

	const windowBlock = (block) => {
		const out = { openStatuses: ids(block?.openStatuses) }
		if (text(block?.closedReason) !== '') {
			out.closedReason = text(block.closedReason)
		}
		return out
	}

	const withdrawal = state?.withdrawal ?? {}
	let portalWithdrawal = {}
	if (withdrawal.enabled && text(withdrawal.targetStatus) !== '') {
		portalWithdrawal = {
			openStatuses: ids(withdrawal.openStatuses),
			targetStatus: text(withdrawal.targetStatus),
		}
		if (text(withdrawal.closedReason) !== '') {
			portalWithdrawal.closedReason = text(withdrawal.closedReason)
		}
		if (text(withdrawal.confirmText) !== '') {
			portalWithdrawal.confirmText = text(withdrawal.confirmText)
		}
	}

	return {
		portalWritable,
		portalAmendmentWindow: windowBlock(state?.amendment),
		portalDocumentWindow: windowBlock(state?.documents),
		portalWithdrawal,
	}
}

/**
 * Write the Portal section to the case type.
 *
 * OpenRegister runs dossiq's pre-save guard on this write, so a withdrawal the
 * workflow cannot reach comes back as a refusal carrying the sentence.
 *
 * @param {string} id The case type's id.
 * @param {object} state The form state.
 * @return {Promise<object>} The saved case type.
 * @spec openspec/specs/portal-contribution/spec.md
 */
export async function savePortalSettings(id, state) {
	const { data } = await axios.patch(
		generateUrl(
			`/apps/openregister/api/objects/dossiq/caseType/${encodeURIComponent(id)}`,
		),
		portalPayload(state),
	)
	return data?.object ?? data ?? {}
}

/**
 * The case type as OpenRegister stores it.
 *
 * @param {string} id The case type's id.
 * @return {Promise<object>} The case type.
 * @spec openspec/specs/portal-contribution/spec.md
 */
export async function readCaseType(id) {
	const { data } = await axios.get(
		generateUrl(
			`/apps/openregister/api/objects/dossiq/caseType/${encodeURIComponent(id)}`,
		),
	)
	return data?.object ?? data ?? {}
}

/**
 * The refusal sentence a save came back with, if it carries one.
 *
 * @param {Error|object} error The rejected request.
 * @return {string} The sentence, or the empty string.
 * @spec openspec/specs/portal-contribution/spec.md
 */
export function refusalSentence(error) {
	const body = error?.response?.data
	return text(body?.errors?.message ?? body?.message ?? '')
}
