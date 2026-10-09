/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure helpers in src/utils/workQueueHelpers.js: the
 * sort modes, the deadline tier pill, the work-queue response map, and the
 * ranked list the Urgency mode renders.
 *
 * @spec openspec/changes/werkvoorraad-intelligent-queue/specs/werkvoorraad-intelligent-queue/spec.md
 * @spec openspec/changes/configurable-queue-urgency/specs/werkvoorraad-intelligent-queue/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	buildUrgencyMap,
	DEADLINE_TIERS,
	deadlineTierLabel,
	deadlineTierPillClass,
	filterRankedRows,
	pageOfRows,
	rankedCaseRows,
	resolveListMode,
	resolveSortConfig,
	SORT_MODES,
} from '../../src/utils/workQueueHelpers.js'

describe('SORT_MODES', () => {
	it('lists urgency and newest', () => {
		expect(SORT_MODES).toEqual(['urgency', 'newest'])
	})
})

describe('resolveSortConfig', () => {
	it('maps "urgency" to deadline ascending', () => {
		expect(resolveSortConfig('urgency')).toEqual({
			key: 'deadline',
			order: 'asc',
		})
	})

	it('maps "newest" to startDate descending', () => {
		expect(resolveSortConfig('newest')).toEqual({
			key: 'startDate',
			order: 'desc',
		})
	})

	it('falls back to the urgency default for an unknown mode', () => {
		expect(resolveSortConfig('bogus')).toEqual({ key: 'deadline', order: 'asc' })
	})

	it('falls back to the urgency default for undefined', () => {
		expect(resolveSortConfig(undefined)).toEqual({
			key: 'deadline',
			order: 'asc',
		})
	})
})

describe('deadlineTierPillClass', () => {
	it('gives every tier a pill, normal included', () => {
		expect(DEADLINE_TIERS.map(deadlineTierPillClass)).toEqual([
			'mywork-card__tier-pill--overdue',
			'mywork-card__tier-pill--critical',
			'mywork-card__tier-pill--warning',
			'mywork-card__tier-pill--normal',
		])
	})

	it('gives no pill for an unknown or falsy tier', () => {
		expect(deadlineTierPillClass('mystery')).toBe('')
		expect(deadlineTierPillClass(undefined)).toBe('')
	})
})

describe('deadlineTierLabel', () => {
	// @spec openspec/changes/configurable-queue-urgency/specs/werkvoorraad-intelligent-queue/spec.md#scenario-overdue-chip
	// @spec openspec/changes/configurable-queue-urgency/specs/werkvoorraad-intelligent-queue/spec.md#scenario-critical-chip
	it('labels the tiers as the board does', () => {
		expect(DEADLINE_TIERS.map(deadlineTierLabel)).toEqual(['Late', 'Critical', 'Soon', 'Normal'])
	})

	it('has no label for an unknown tier', () => {
		expect(deadlineTierLabel('')).toBe('')
	})
})

describe('resolveListMode', () => {
	it('renders the ranked queue for Urgency while it loads and once it answered', () => {
		expect(resolveListMode('urgency', 'loading')).toBe('ranked')
		expect(resolveListMode('urgency', 'ready')).toBe('ranked')
	})

	// @spec openspec/changes/configurable-queue-urgency/specs/werkvoorraad-intelligent-queue/spec.md#scenario-the-queue-fails-and-the-list-says-it-orders-by-deadline
	it('falls back to the self-fetch when the queue failed', () => {
		expect(resolveListMode('urgency', 'failed')).toBe('self')
		expect(resolveSortConfig('urgency')).toEqual({ key: 'deadline', order: 'asc' })
	})

	it('always self-fetches for Newest', () => {
		expect(resolveListMode('newest', 'ready')).toBe('self')
	})
})

describe('rankedCaseRows', () => {
	// @spec openspec/changes/configurable-queue-urgency/specs/werkvoorraad-intelligent-queue/spec.md#scenario-urgency-does-not-follow-the-deadline-alone
	it('keeps the server order, not the deadline order', () => {
		const items = [
			{ itemType: 'case', id: 'urgent', score: 527, case: { id: 'urgent', deadline: '2026-07-21' } },
			{ itemType: 'task', id: 't1', score: 520 },
			{ itemType: 'case', id: 'low', score: 495, case: { id: 'low', deadline: '2026-07-20' } },
			{ itemType: 'case', id: 'no-row', score: 400 },
		]
		expect(rankedCaseRows(items).map((row) => row.id)).toEqual(['urgent', 'low'])
	})
})

describe('filterRankedRows', () => {
	const rows = [
		{ id: 'a', title: 'Kapvergunning essen', identifier: '2026-0071', status: 's1', caseType: { id: 'ct1' }, deadline: '2026-10-09' },
		{ id: 'b', title: 'Bezwaar parkeerboete', identifier: '2026-0074', status: 's2', caseType: 'ct2', deadline: '2026-10-20' },
		{ id: 'c', title: 'Woo-verzoek evenementen', identifier: '2026-0076', status: 's1', caseType: 'ct1', deadline: '2026-11-01' },
	]

	// @spec openspec/changes/configurable-queue-urgency/specs/werkvoorraad-intelligent-queue/spec.md#scenario-search-narrows-the-ranked-list
	it('narrows by title or case number and keeps the order', () => {
		expect(filterRankedRows(rows, 'VERGUNNING').map((r) => r.id)).toEqual(['a'])
		expect(filterRankedRows(rows, '0074').map((r) => r.id)).toEqual(['b'])
		expect(filterRankedRows(rows, '').map((r) => r.id)).toEqual(['a', 'b', 'c'])
	})

	it('applies list filters, matching an id or an object with one', () => {
		expect(filterRankedRows(rows, '', { caseType: ['ct1'] }).map((r) => r.id)).toEqual(['a', 'c'])
		expect(filterRankedRows(rows, '', { status: ['s1'], caseType: [] }).map((r) => r.id)).toEqual(['a', 'c'])
	})

	it('applies a date window', () => {
		expect(filterRankedRows(rows, '', { deadline: { from: '2026-10-10', to: '2026-10-31' } }).map((r) => r.id)).toEqual(['b'])
	})
})

describe('pageOfRows', () => {
	it('slices a page and reports the pagination', () => {
		const rows = Array.from({ length: 45 }, (_, i) => ({ id: String(i) }))
		const page = pageOfRows(rows, 3, 20)
		expect(page.rows.map((r) => r.id)).toEqual(['40', '41', '42', '43', '44'])
		expect(page.pagination).toEqual({ page: 3, pages: 3, total: 45, limit: 20 })
	})

	it('clamps a page past the end and answers one empty page for no rows', () => {
		expect(pageOfRows([{ id: 'x' }], 9, 20).pagination.page).toBe(1)
		expect(pageOfRows([], 1, 20)).toEqual({ rows: [], pagination: { page: 1, pages: 1, total: 0, limit: 20 } })
	})
})

describe('buildUrgencyMap', () => {
	it('keys case items by id', () => {
		const items = [
			{
				itemType: 'case',
				id: 'case-1',
				deadlineTier: 'overdue',
				score: 1005,
				daysUntilDeadline: -2,
			},
			{
				itemType: 'case',
				id: 'case-2',
				deadlineTier: 'normal',
				score: 260,
				daysUntilDeadline: 30,
			},
		]
		expect(buildUrgencyMap(items)).toEqual({
			'case-1': { deadlineTier: 'overdue', score: 1005, daysUntilDeadline: -2 },
			'case-2': { deadlineTier: 'normal', score: 260, daysUntilDeadline: 30 },
		})
	})

	it('skips task items', () => {
		const items = [
			{
				itemType: 'task',
				id: 'task-1',
				deadlineTier: 'overdue',
				score: 1005,
				daysUntilDeadline: -2,
			},
			{
				itemType: 'case',
				id: 'case-1',
				deadlineTier: 'warning',
				score: 493,
				daysUntilDeadline: 7,
			},
		]
		const map = buildUrgencyMap(items)
		expect(Object.keys(map)).toEqual(['case-1'])
	})

	it('skips items missing an id', () => {
		const items = [
			{ itemType: 'case', id: '', deadlineTier: 'overdue', score: 1005 },
			{ itemType: 'case', deadlineTier: 'overdue', score: 1005 },
		]
		expect(buildUrgencyMap(items)).toEqual({})
	})

	it('skips null/undefined entries and returns {} for empty/missing input', () => {
		expect(buildUrgencyMap([null, undefined])).toEqual({})
		expect(buildUrgencyMap([])).toEqual({})
		expect(buildUrgencyMap(undefined)).toEqual({})
	})
})
