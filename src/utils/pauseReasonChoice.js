/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The choice of a pause reason when a case is suspended.
 *
 * A case type that declares pause reasons offers them in a picker, with an
 * "Other" entry that keeps the free text a handler can always write. A case
 * type that declares none keeps the free-text reason alone.
 *
 * @spec openspec/changes/suspend-picks-a-pause-reason/specs/termijn-pause-extension/spec.md
 */

/**
 * The picker value of the "Other" entry. Never sent to the server.
 *
 * @type {string}
 */
export const OTHER_PAUSE_REASON = '__other'

/**
 * Whether the suspend form offers a picker at all.
 *
 * @param {object} act The chosen act.
 * @param {Array<object>} reasons The case type's pause reasons.
 * @return {boolean} True when the act is suspend and reasons are declared.
 * @spec openspec/changes/suspend-picks-a-pause-reason/specs/termijn-pause-extension/spec.md
 */
export function offersPauseReasons(act, reasons) {
	return String(act?.id ?? '') === 'suspend' && Array.isArray(reasons) && reasons.length > 0
}

/**
 * The declared reason the picker holds, or null for none or Other.
 *
 * @param {Array<object>} reasons The case type's pause reasons.
 * @param {string} key The picker value.
 * @return {object|null} The reason.
 * @spec openspec/changes/suspend-picks-a-pause-reason/specs/termijn-pause-extension/spec.md
 */
export function pickedPauseReason(reasons, key) {
	if (!key || key === OTHER_PAUSE_REASON || !Array.isArray(reasons)) {
		return null
	}

	return reasons.find((reason) => reason?.key === key) ?? null
}

/**
 * Whether the free-text reason is asked for and required.
 *
 * Always, unless the form offers a picker; with a picker, only once Other is picked.
 *
 * @param {object} act The chosen act.
 * @param {Array<object>} reasons The case type's pause reasons.
 * @param {string} key The picker value.
 * @return {boolean} True when the text field shows and must be filled.
 * @spec openspec/changes/suspend-picks-a-pause-reason/specs/termijn-pause-extension/spec.md
 */
export function asksReasonText(act, reasons, key) {
	if (!offersPauseReasons(act, reasons)) {
		return true
	}

	return key === OTHER_PAUSE_REASON
}

/**
 * The pause reason key to post: a declared key, or '' for Other and for none.
 *
 * @param {string} key The picker value.
 * @return {string} The key.
 * @spec openspec/changes/suspend-picks-a-pause-reason/specs/termijn-pause-extension/spec.md
 */
export function pauseReasonToSend(key) {
	return key && key !== OTHER_PAUSE_REASON ? key : ''
}
