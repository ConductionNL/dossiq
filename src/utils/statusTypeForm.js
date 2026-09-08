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
		checklist: [],
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
		.filter((item) => typeof item?.title === 'string' && item.title.trim() !== '')
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
		checklist: pruneChecklist(row.checklist),
	}
}

/**
 * The payload a form saves as.
 *
 * The inverse of `statusTypeToForm`: it writes back exactly the properties the
 * schema declares and nothing else, so a row that arrived carrying dead
 * properties leaves without them.
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
		checklist: pruneChecklist(form.checklist),
	}

	if (form.id) {
		payload.id = form.id
	}

	if (form.caseType) {
		payload.caseType = form.caseType
	}

	return payload
}
