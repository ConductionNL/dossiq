/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure, framework-free helpers for the My Work intelligent queue: sort-mode
 * → CnIndexPage sort-key mapping, urgency tier → CSS chip class mapping, and
 * a { caseId: {...} } lookup builder from the GET /api/work-queue response.
 * Extracted so all three are unit-testable without mounting the Vue
 * component (mirrors src/utils/caseRelationHelpers.js).
 *
 * @spec openspec/changes/werkvoorraad-intelligent-queue/specs/werkvoorraad-intelligent-queue/spec.md
 */

/**
 * The two sort modes the My Work sort toggle supports.
 *
 * @type {string[]}
 */
export const SORT_MODES = ['urgency', 'newest']

/**
 * Resolve a My Work sort-mode into the CnIndexPage self-fetch sort params.
 *
 * 'urgency' orders by the case's own computed `deadline` field ascending
 * (soonest deadline first) — a real, always-present schema field, so the
 * ordering happens server-side without bypassing CnIndexPage's self-fetch
 * (which would drop the sidebar search/facet filtering). The more precise
 * composite urgency score (termijn extensions, priority, case age) computed
 * by WorkQueueService is surfaced per-card via the urgency chip instead.
 * Any input other than 'newest' resolves to the urgency default.
 *
 * @param {string} mode 'urgency' or 'newest'.
 * @return {{key: string, order: string}} CnIndexPage sortKey/sortOrder.
 */
export function resolveSortConfig(mode) {
	if (mode === 'newest') {
		return { key: 'startDate', order: 'desc' }
	}
	return { key: 'deadline', order: 'asc' }
}

/**
 * Map a WorkQueueService urgency tier to a chip CSS modifier class.
 *
 * @param {string} tier 'overdue' | 'critical' | 'warning' | 'normal' | falsy.
 * @return {string} CSS class name, '' when no chip should render (normal tier
 *   or an unknown/falsy value).
 */
export function urgencyChipClass(tier) {
	switch (tier) {
	case 'overdue':
		return 'mywork-card__urgency-chip--overdue'
	case 'critical':
		return 'mywork-card__urgency-chip--critical'
	case 'warning':
		return 'mywork-card__urgency-chip--warning'
	default:
		return ''
	}
}

/**
 * Build a { caseId: { tier, score, daysUntilDeadline } } lookup map from the
 * GET /api/work-queue response's `items` array. Task-type items are
 * skipped — My Work's card urgency chip is keyed by case id only.
 *
 * @param {Array<object>} items Work-queue response `items` array.
 * @return {{[caseId: string]: {tier: string, score: number, daysUntilDeadline: (number|null)}}}
 *   Map keyed by case id.
 */
export function buildUrgencyMap(items) {
	const map = {}
	for (const item of (items || [])) {
		if (!item || item.itemType !== 'case' || !item.id) {
			continue
		}
		map[item.id] = {
			tier: item.tier,
			score: item.score,
			daysUntilDeadline: item.daysUntilDeadline,
		}
	}
	return map
}
