// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Date-range presets for the dashboard KPI pills. Each preset resolves to a
// calendar-aligned lower bound (start of this week/month/quarter/year); 'all'
// has no bound. A record falls "in range" when its chosen date field is on or
// after that bound. Calendar-aligned (not rolling) to match the original
// "Completed This Month" semantics the dashboard shipped with.

import { translate as t } from '@nextcloud/l10n'

/**
 * Ordered range presets shown as KPI pills.
 *
 * @return {Array<{ id: string, label: string }>}
 */
export function rangeOptions() {
	return [
		{ id: 'week', label: t('procest', 'Week') },
		{ id: 'month', label: t('procest', 'Month') },
		{ id: 'quarter', label: t('procest', 'Quarter') },
		{ id: 'year', label: t('procest', 'Year') },
		{ id: 'all', label: t('procest', 'All') },
	]
}

/**
 * Resolve the inclusive lower bound (local midnight) for a range preset.
 *
 * @param {string} rangeId One of week|month|quarter|year|all.
 * @param {Date} [now] Reference "now" (defaults to current time).
 * @return {Date|null} Start of the period, or null for 'all' (no bound).
 */
export function rangeStart(rangeId, now = new Date()) {
	const d = new Date(now)
	d.setHours(0, 0, 0, 0)
	switch (rangeId) {
	case 'week': {
		const day = d.getDay() // 0 = Sunday
		const diff = day === 0 ? 6 : day - 1 // days since Monday
		d.setDate(d.getDate() - diff)
		return d
	}
	case 'month':
		d.setDate(1)
		return d
	case 'quarter': {
		const quarterFirstMonth = Math.floor(d.getMonth() / 3) * 3
		d.setMonth(quarterFirstMonth, 1)
		return d
	}
	case 'year':
		d.setMonth(0, 1)
		return d
	case 'all':
	default:
		return null
	}
}

/**
 * Whether an ISO date string falls within the given range (on/after its start).
 * For 'all' everything matches; otherwise an empty/absent date is never in
 * range (a record with no relevant date can't be placed in a window).
 *
 * @param {string|null|undefined} dateStr ISO date (or datetime) string.
 * @param {string} rangeId Range preset id.
 * @param {Date} [now] Reference "now".
 * @return {boolean}
 */
export function isInRange(dateStr, rangeId, now = new Date()) {
	if (rangeId === 'all') return true
	if (!dateStr) return false
	const start = rangeStart(rangeId, now)
	if (!start) return true
	return new Date(dateStr) >= start
}
