// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
//
// Pure presentation helpers for the deelzaak (sub-case) UI surface.
//
// These functions hold the user-facing string + visibility logic for the
// case-list sub-case count badge (T10) and the parent-deletion orphan
// warning (T11). They are deliberately DOM-free and side-effect-free so the
// vitest suite (node environment) can pin the exact rendered copy without a
// browser. The Vue layer (formatters.js, DeelzaakList.vue, the orphan
// warning modal) consumes them.

import { translate as t } from '@nextcloud/l10n'

/**
 * Badge label for a sub-case count.
 *
 * Returns an empty string for counts <= 0 so the caller renders NO badge for
 * cases without sub-cases (spec REQ-DZS-005-B). For positive counts it
 * returns "N deelzaken" (the user-facing copy lives in the en/nl l10n).
 *
 * @param {number} count Number of sub-cases for the case.
 * @return {string} Badge label, or '' when no badge should be shown.
 * @spec openspec/changes/deelzaak-support/tasks.md#T10
 */
export function subCaseCountBadge(count) {
	const n = Number(count)
	if (!Number.isFinite(n) || n <= 0) {
		return ''
	}
	return t('procest', '{count} deelzaken', { count: n })
}

/**
 * Whether a sub-case count should render a badge at all.
 *
 * @param {number} count Number of sub-cases for the case.
 * @return {boolean} True when count > 0.
 * @spec openspec/changes/deelzaak-support/tasks.md#T10
 */
export function hasSubCaseBadge(count) {
	const n = Number(count)
	return Number.isFinite(n) && n > 0
}

/**
 * Warning message shown before deleting a parent case that has sub-cases.
 *
 * Mirrors the exact copy mandated by spec REQ-DZS-006-A: deleting unlinks the
 * sub-cases from their parent rather than cascade-deleting them.
 *
 * @param {number} count Number of sub-cases attached to the parent.
 * @return {string} The localized warning sentence.
 * @spec openspec/changes/deelzaak-support/tasks.md#T11
 */
export function orphanWarningMessage(count) {
	const n = Math.max(0, Number(count) || 0)
	return t(
		'procest',
		'This case has {count} sub-cases. Deleting it will unlink the sub-cases from their parent. Do you want to continue?',
		{ count: n },
	)
}

/**
 * Whether deleting a case requires the orphan-warning flow.
 *
 * A parent with one or more sub-cases needs the warning + unlink step; a case
 * with no sub-cases takes the standard delete confirmation (REQ-DZS-006-C).
 *
 * @param {number} count Number of sub-cases attached to the case.
 * @return {boolean} True when the orphan-warning dialog must be shown first.
 * @spec openspec/changes/deelzaak-support/tasks.md#T11
 */
export function requiresOrphanWarning(count) {
	const n = Number(count)
	return Number.isFinite(n) && n > 0
}
