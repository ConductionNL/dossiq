// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What a status's limit means on the board.
//
// 🔴 A BOARD COLUMN IS NOT A STATUS. The board merges every non-final status
// type sharing a NAME into one column, across every case type, and a capacity
// is authored on ONE status type. So nothing here ever reasons about a
// column's length: the count is always of the cases carrying the CONCRETE
// status a case is moving into. A header reading "9 of 12" beside an engine
// that refuses at four is worse than no number at all, because the number is
// exactly what a handler plans against.
//
// 🔴 THIS IS THE AFFORDANCE, NOT THE CONTROL. The engine's `CapacityGuard`
// refuses the move and its answer is the authority. This runs first so a
// handler does not watch a card slide back, and it counts only what the board
// has loaded, which is why it can never be the one that decides.
//
// @spec openspec/changes/status-capacity-limit/specs/status-transition-engine/spec.md

/**
 * The limit a status type declares, as a usable number.
 *
 * @param {object} statusType The status type row.
 * @return {number|null} The limit, or null when it carries none.
 */
export function capacityOf(statusType) {
	const declared = Number(statusType && statusType.capacity)

	return declared > 0 ? declared : null
}

/**
 * How many of the loaded cases sit in one concrete status.
 *
 * @param {Array} cases The cases the board loaded for the column.
 * @param {string} statusTypeId The concrete status type id.
 * @return {number} The count.
 */
export function countInStatus(cases, statusTypeId) {
	return (cases || []).filter(
		(one) => String(one && one.status) === String(statusTypeId),
	).length
}

/**
 * Whether a status is at or past its limit.
 *
 * @param {object} statusType The status type row.
 * @param {number} count The number of cases in it.
 * @return {boolean} True when it is full.
 */
export function isFull(statusType, count) {
	const capacity = capacityOf(statusType)

	return capacity !== null && count >= capacity
}

/**
 * What the column header says: the count, and the limit when there is one.
 *
 * A column with no limit reads exactly as it did before this existed.
 *
 * @param {number} count The number of cases in the column.
 * @param {number|null} capacity The limit, or null.
 * @return {string} The label.
 */
export function countLabel(count, capacity) {
	if (capacity === null || !(capacity > 0)) {
		return String(count)
	}

	return `${count} / ${capacity}`
}

/**
 * Why a card may not land on a status, when it may not.
 *
 * The sentence takes the same shape the engine answers with, because the two
 * are read in the same place by the same person and a second wording would
 * read as a second rule.
 *
 * A final status is never capped: a case type that could not close its
 * thirteenth case would be worse off than one with no limit at all.
 *
 * @param {object} statusType The concrete status being entered.
 * @param {Array} cases The cases the board loaded for that column.
 * @param {(text: string, params: object) => string} translate The translator.
 * @return {string} The refusal, or '' when the move may go ahead.
 */
export function capacityRefusal(statusType, cases, translate) {
	const capacity = capacityOf(statusType)
	if (capacity === null || statusType.isFinal === true) {
		return ''
	}

	const count = countInStatus(cases, statusType.id)
	if (count < capacity) {
		return ''
	}

	return translate('Status {status} is full: {count} of {limit} cases.', {
		status: statusType.name || String(statusType.id),
		count: String(count),
		limit: String(capacity),
	})
}
