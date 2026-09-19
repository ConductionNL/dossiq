/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a document row says about the approval route it is in.
 *
 * 🔴 IT IS A READ AND NEVER A STORED COPY. decidiq owns the route and dossiq
 * asks it, once per Files tab, through
 * `GET /apps/dossiq/api/informatieobjecten/approval-markers`. A copy written
 * onto the document would be written once and would then disagree with the
 * route the first time somebody approved from decidiq's own page, and the row
 * would go on saying "step two of three" after the route had finished.
 *
 * 🔴 A DOCUMENT WITH NO ROUTE HAS NO MARKER, and that is not the same as a
 * route that has cleared. "Nothing is waiting on this" and "three people
 * agreed" are different rows, and folding them together loses the one a
 * handler was looking for.
 *
 * @spec openspec/changes/approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * The marker text for one document, from the answer the endpoint gave.
 *
 * @param {object} [marker] The entry for one document, or undefined.
 *
 * @return {string} The text for the row, or an empty string for no marker.
 * @spec openspec/changes/approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md
 */
export function approvalMarkerLabel(marker) {
	if (!marker || marker.routed !== true) {
		return ''
	}

	if (marker.cleared === true) {
		return t('dossiq', 'Approved')
	}

	const waiting = Array.isArray(marker.waitingOn) ? marker.waitingOn : []
	const first = waiting[0] || {}
	const step = Number(first.stage || 0)
	const total = Number(marker.stepCount || 0)

	if (step > 0 && total > 0) {
		return t('dossiq', 'In approval, step {step} of {total}', { step, total })
	}

	if (step > 0) {
		return t('dossiq', 'In approval, step {step}', { step })
	}

	return t('dossiq', 'In approval')
}

/**
 * Whether a row should carry a marker at all.
 *
 * @param {object} [marker] The entry for one document, or undefined.
 *
 * @return {boolean} True when the document is in a route.
 * @spec openspec/changes/approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md
 */
export function isInApprovalRoute(marker) {
	return Boolean(marker && marker.routed === true)
}
