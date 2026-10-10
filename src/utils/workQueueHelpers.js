/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure, framework-free helpers for the My Work intelligent queue: the sort
 * modes, the deadline tier pill, the { caseId: {...} } lookup built from
 * GET /api/work-queue, and the ranked list the Urgency mode renders (its
 * search, filters and pages). Extracted so all of it is unit-testable without
 * mounting the Vue component (mirrors src/utils/caseRelationHelpers.js).
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * The two sort modes the My Work sort toggle supports.
 *
 * @type {string[]}
 */
export const SORT_MODES = ['urgency', 'newest']

/**
 * Resolve a My Work sort-mode into the CnIndexPage self-fetch sort params.
 *
 * Only the self-fetching list reads this. 'newest' orders by `startDate`
 * descending. 'urgency' normally renders the ranked work queue instead (see
 * resolveListMode); this deadline-ascending order is its fallback for when
 * the queue could not be computed. Any input other than 'newest' resolves to
 * that fallback.
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
 * The deadline tiers (termijnstatus) the work queue answers, in order.
 *
 * @type {string[]}
 */
export const DEADLINE_TIERS = ['overdue', 'critical', 'warning', 'normal']

/**
 * Map a deadline tier to its pill CSS modifier class.
 *
 * Every tier has a pill, `normal` included, as the board
 * dossiq/DqAanMijToegewezen draws it.
 *
 * @param {string} tier 'overdue' | 'critical' | 'warning' | 'normal' | falsy.
 * @return {string} CSS class name, '' for an unknown or falsy value (no pill).
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
export function deadlineTierPillClass(tier) {
	return DEADLINE_TIERS.includes(tier) ? `mywork-card__tier-pill--${tier}` : ''
}

/**
 * The pill label for a deadline tier, as the board names it.
 *
 * @param {string} tier 'overdue' | 'critical' | 'warning' | 'normal' | falsy.
 * @return {string} The translated label (Te laat, Kritiek, Bijna, Normaal), or '' for no pill.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
export function deadlineTierLabel(tier) {
	switch (tier) {
		case 'overdue':
			return t('dossiq', 'Late')
		case 'critical':
			return t('dossiq', 'Critical')
		case 'warning':
			return t('dossiq', 'Soon')
		case 'normal':
			return t('dossiq', 'Normal')
		default:
			return ''
	}
}

/**
 * Build a { caseId: { deadlineTier, score, daysUntilDeadline } } lookup map from the
 * GET /api/work-queue response's `items` array. Task-type items are
 * skipped: My Work's card pill is keyed by case id only.
 *
 * @param {Array<object>} items Work-queue response `items` array.
 * @return {{[caseId: string]: {deadlineTier: string, score: number, daysUntilDeadline: (number|null)}}}
 *   Map keyed by case id.
 */
export function buildUrgencyMap(items) {
	const map = {}
	for (const item of items || []) {
		if (!item || item.itemType !== 'case' || !item.id) {
			continue
		}
		map[item.id] = {
			deadlineTier: item.deadlineTier,
			score: item.score,
			daysUntilDeadline: item.daysUntilDeadline,
		}
	}
	return map
}

/**
 * Which list My Work renders.
 *
 * 'ranked' is the server's order: the work queue, highest score first. It is
 * the Urgency mode whenever the queue answered or is still answering. 'self'
 * is CnIndexPage's own fetch, which Newest always uses and which Urgency
 * falls back to, ordered by deadline, when the queue failed.
 *
 * @param {string} sortMode 'urgency' or 'newest'.
 * @param {string} queueState 'loading' | 'ready' | 'failed'.
 * @return {string} 'ranked' or 'self'.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
export function resolveListMode(sortMode, queueState) {
	if (sortMode === 'newest' || queueState === 'failed') {
		return 'self'
	}
	return 'ranked'
}

/**
 * The case rows of the work queue, in the server's order.
 *
 * @param {Array<object>} items Work-queue response `items` array, ranked.
 * @return {Array<object>} The case rows, highest score first.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
export function rankedCaseRows(items) {
	const rows = []
	for (const item of items || []) {
		if (
			item
			&& item.itemType === 'case'
			&& item.case
			&& typeof item.case === 'object'
		) {
			rows.push(item.case)
		}
	}
	return rows
}

/**
 * The id a row's field names, whether it holds the id or the object.
 *
 * @param {unknown} value The field value.
 * @return {unknown} The comparable value.
 */
function comparable(value) {
	if (value && typeof value === 'object' && !Array.isArray(value)) {
		return value.id ?? value.uuid ?? value.value ?? ''
	}
	return value
}

/**
 * Whether one row passes one sidebar filter.
 *
 * A list of values matches when the row's value (or any of its values) is
 * among them; a `{ from, to }` window matches a date inside it.
 *
 * @param {object} row The case row.
 * @param {string} key The field.
 * @param {unknown} wanted The filter's values.
 * @return {boolean} True when the row passes.
 */
function passesFilter(row, key, wanted) {
	if (wanted === undefined || wanted === null || wanted === '') {
		return true
	}
	const actual = row[key]
	if (Array.isArray(wanted)) {
		if (wanted.length === 0) {
			return true
		}
		const values = (Array.isArray(actual) ? actual : [actual])
			.map(comparable)
			.map(String)
		return wanted.some((w) => values.includes(String(comparable(w))))
	}
	if (typeof wanted === 'object') {
		const day = String(actual || '').slice(0, 10)
		if (!day) {
			return false
		}
		const from = wanted.from ? String(wanted.from).slice(0, 10) : ''
		const to = wanted.to ? String(wanted.to).slice(0, 10) : ''
		return (!from || day >= from) && (!to || day <= to)
	}
	return String(comparable(actual)) === String(wanted)
}

/**
 * Narrow the ranked rows by the search box and the sidebar's filters,
 * keeping their order.
 *
 * The rows are exactly the reader's open cases, already in hand, so this
 * happens in the browser. The search matches the title, the case number and
 * the description, ignoring case.
 *
 * @param {Array<object>} rows The ranked case rows.
 * @param {string} search The search term.
 * @param {{[key: string]: unknown}} filters The sidebar's active filters.
 * @return {Array<object>} The rows that pass, in ranked order.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
export function filterRankedRows(rows, search = '', filters = {}) {
	const needle = String(search || '')
		.trim()
		.toLowerCase()
	return (rows || []).filter((row) => {
		if (needle) {
			const haystack = [row.title, row.identifier, row.description]
				.filter(Boolean)
				.join(' ')
				.toLowerCase()
			if (!haystack.includes(needle)) {
				return false
			}
		}
		return Object.entries(filters || {}).every(([key, wanted]) =>
			passesFilter(row, key, wanted),
		)
	})
}

/**
 * One page of the ranked rows, with the pagination CnIndexPage reads.
 *
 * @param {Array<object>} rows The rows, filtered and ranked.
 * @param {number} page The 1-based page.
 * @param {number} limit Rows per page.
 * @return {{rows: Array<object>, pagination: {page: number, pages: number, total: number, limit: number}}}
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
export function pageOfRows(rows, page = 1, limit = 20) {
	const list = rows || []
	const size = Math.max(1, Number(limit) || 20)
	const pages = Math.max(1, Math.ceil(list.length / size))
	const current = Math.min(Math.max(1, Number(page) || 1), pages)
	return {
		rows: list.slice((current - 1) * size, current * size),
		pagination: { page: current, pages, total: list.length, limit: size },
	}
}
