// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What a status type is, as a form.
//
// The `statusType` schema carries nine properties. Until this file existed the
// authoring surface edited four of them, and two of those four (notifyInitiator
// and notificationText) are declared by no schema and read by no code: they were
// controls that configured nothing. Meanwhile `role`, `colour`, `hiddenInLists`
// and `checklist` all reach the running product — a flow addresses a status by
// role, four rendering surfaces read the colour, the Cases index filters on
// hiddenInLists, and every checklist item becomes a task on the case — and none
// of them could be set without hand-editing register JSON.
//
// The shape lives here rather than in the tab so the normalising can be tested
// without mounting anything. Normalising is the part that matters: a status row
// saved before these properties existed carries none of them, and the form has
// to open on such a row without inventing values the author did not choose.
//
// @spec openspec/specs/case-types/spec.md

import { STATUS_COLOURS } from './statusColour.js'
import { pruneFieldRules } from './statusFieldRules.js'

/**
 * The roles a status may declare, in the schema's own order.
 *
 * A role is what the status MEANS, independent of what it is called, so a
 * shipped flow moves a case on a type that calls its working phase Beoordeling.
 */
export const STATUS_ROLES = [
	'intake',
	'pending-info',
	'in-progress',
	'review',
	'closed',
	'stranded',
]

/**
 * Who a status may declare the case is waiting on.
 *
 * The applicant and a third party are DIFFERENT values, because the Awb treats
 * them differently: a hersteltermijn suspends the beslistermijn and an advice
 * request does not. There is no fourth value for "not declared" — an empty
 * declaration means the case is ours to move, which is what keeps the team
 * count usable on a case type nobody has annotated.
 */
export const STATUS_WAITING_ON = ['us', 'applicant', 'thirdParty']

/**
 * Whether a value is one of the three the schema enumerates.
 *
 * @param {unknown} waitingOn The candidate value.
 * @return {boolean} True when the value is in the list.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
export function isWaitingOn(waitingOn) {
	return typeof waitingOn === 'string' && STATUS_WAITING_ON.includes(waitingOn)
}

/**
 * A maximum dwell as the form holds it.
 *
 * Zero, a negative and anything unreadable all become the empty string, which
 * is "no maximum". A maximum of zero would breach every case the instant it
 * entered the status, so honouring it would be worse than refusing it.
 *
 * @param {unknown} maximumDwell The candidate value.
 * @return {number|string} A positive whole number, or '' for no maximum.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
export function normaliseMaximumDwell(maximumDwell) {
	const days = Number(maximumDwell)
	if (!Number.isFinite(days) || days < 1) {
		return ''
	}
	return Math.floor(days)
}

/**
 * Whether a value is one of the roles the schema enumerates.
 *
 * @param {unknown} role The candidate role.
 * @return {boolean} True when the name is in the list.
 *
 * @spec openspec/specs/case-types/spec.md
 */
export function isStatusRole(role) {
	return typeof role === 'string' && STATUS_ROLES.includes(role)
}

/**
 * An empty status type form.
 *
 * @param {number} order The order to open on.
 * @return {object} A form with every editable property present.
 *
 * @spec openspec/specs/case-types/spec.md
 */
export function emptyStatusTypeForm(order = 1) {
	return {
		name: '',
		description: '',
		order,
		isFinal: false,
		role: '',
		colour: '',
		hiddenInLists: false,
		waitingOn: '',
		maximumDwell: '',
		checklist: [],
		fieldRules: [],
		derivedWhen: [],
	}
}

/**
 * One checklist item, as the schema declares it.
 *
 * @param {string} title What has to be done.
 * @param {boolean} required Whether the case may leave the status before it is done.
 * @return {{title: string, required: boolean}} The item.
 *
 * @spec openspec/specs/case-types/spec.md
 */
export function checklistItem(title = '', required = false) {
	return { title, required: required === true }
}

/**
 * Drop the checklist entries that would save as blank rows.
 *
 * An item with no title is a task with no title, and a task with no title is
 * unactionable the moment the case enters the status. The author gets an empty
 * row while they type; the store never does.
 *
 * @param {unknown} checklist The checklist as the form holds it.
 * @return {Array<{title: string, required: boolean}>} The items worth saving.
 *
 * @spec openspec/specs/case-types/spec.md
 */
export function pruneChecklist(checklist) {
	if (Array.isArray(checklist) === false) {
		return []
	}

	return checklist
		.filter(
			(item) => typeof item?.title === 'string' && item.title.trim() !== '',
		)
		.map((item) => checklistItem(item.title.trim(), item.required === true))
}

/**
 * Open a stored status type in the form without inventing values.
 *
 * A row saved before `role`, `colour`, `hiddenInLists` or `checklist` existed
 * carries none of them, and a row saved by an older UI carries two properties
 * (notifyInitiator, notificationText) no schema declares. Both cases have to
 * open: the first as unset rather than as a guess, the second without carrying
 * the dead properties back into the store on the next save.
 *
 * @param {object} statusType The stored row.
 * @return {object} The form.
 *
 * @spec openspec/specs/case-types/spec.md
 */
export function statusTypeToForm(statusType) {
	const row = statusType || {}

	return {
		id: row.id,
		caseType: row.caseType,
		name: typeof row.name === 'string' ? row.name : '',
		description: typeof row.description === 'string' ? row.description : '',
		order: Number.isFinite(Number(row.order)) ? Number(row.order) : 0,
		isFinal: row.isFinal === true || row.isFinal === 'true',
		role: isStatusRole(row.role) ? row.role : '',
		colour: STATUS_COLOURS.includes(row.colour) ? row.colour : '',
		hiddenInLists: row.hiddenInLists === true || row.hiddenInLists === 'true',
		waitingOn: isWaitingOn(row.waitingOn) ? row.waitingOn : '',
		maximumDwell: normaliseMaximumDwell(row.maximumDwell),
		checklist: pruneChecklist(row.checklist),
		fieldRules: pruneFieldRules(row.fieldRules),
		derivedWhen: Array.isArray(row.derivedWhen) ? row.derivedWhen : [],
	}
}

/**
 * The payload a form saves as.
 *
 * The inverse of `statusTypeToForm`: it writes back exactly the properties the
 * schema declares and nothing else, so a row that arrived carrying dead
 * properties leaves without them.
 *
 * 🔴 A SCHEMA PROPERTY MISSING FROM BOTH HALVES IS DESTROYED ON THE NEXT SAVE,
 * in silence. `derivedWhen` shipped on the schema with no authoring surface and
 * was absent here, so opening a status and pressing Save, or simply dragging a
 * status to reorder it, wrote the row back without its conditions and the
 * derivation quietly stopped. It is carried through untouched now, and
 * `fieldRules` was added to both halves the same day it was added to the
 * schema. Add a property to the schema, add it here.
 *
 * @param {object} form The form.
 * @return {object} The object to save.
 *
 * @spec openspec/specs/case-types/spec.md
 */
export function formToStatusType(form) {
	const payload = {
		name: String(form.name ?? '').trim(),
		description: String(form.description ?? '').trim(),
		order: Number(form.order) || 0,
		isFinal: form.isFinal === true,
		role: isStatusRole(form.role) ? form.role : '',
		colour: STATUS_COLOURS.includes(form.colour) ? form.colour : '',
		hiddenInLists: form.hiddenInLists === true,
		waitingOn: isWaitingOn(form.waitingOn) ? form.waitingOn : '',
		maximumDwell: normaliseMaximumDwell(form.maximumDwell),
		checklist: pruneChecklist(form.checklist),
		fieldRules: pruneFieldRules(form.fieldRules),
		derivedWhen: Array.isArray(form.derivedWhen) ? form.derivedWhen : [],
	}

	if (form.id) {
		payload.id = form.id
	}

	if (form.caseType) {
		payload.caseType = form.caseType
	}

	return payload
}
