// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The board narrowed to one case type (DqWerkbord): the columns of that type
 * and only its cases, while the merged board underneath keeps every case.
 *
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-027
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { mergeColumnBack, narrowBoard } from '../../src/utils/boardCaseTypeFilter.js'

const ROOT = path.resolve(__dirname, '../..')
const boardSource = fs.readFileSync(
	path.join(ROOT, 'src', 'views', 'workflow-board', 'WorkflowBoard.vue'),
	'utf8',
)

const statusTypes = [
	{ id: 'w1', name: 'Ontvangen', caseType: 'woo', isFinal: false },
	{ id: 'w2', name: 'In behandeling', caseType: 'woo', isFinal: false },
	{ id: 'w3', name: 'Afgehandeld', caseType: 'woo', isFinal: 'true' },
	{ id: 'b1', name: 'Ontvangen', caseType: 'bezwaar', isFinal: false },
	{ id: 'b2', name: 'Hoorzitting', caseType: 'bezwaar', isFinal: false },
]
const columns = [
	{ id: 'Ontvangen', name: 'Ontvangen' },
	{ id: 'In behandeling', name: 'In behandeling' },
	{ id: 'Hoorzitting', name: 'Hoorzitting' },
]
const casesByStatus = {
	Ontvangen: [
		{ id: '1', caseType: 'woo' },
		{ id: '2', caseType: 'bezwaar' },
	],
	'In behandeling': [{ id: '3', caseType: 'woo' }],
	Hoorzitting: [{ id: '4', caseType: 'bezwaar' }],
}

describe('narrowBoard', () => {
	it('draws the whole merged board when no case type is chosen', () => {
		expect(
			narrowBoard({ columns, casesByStatus, statusTypes, caseType: '' }),
		).toEqual({
			columns,
			casesByStatus,
		})
	})

	it('keeps the columns of the chosen type, in board order, and only its cases', () => {
		const out = narrowBoard({
			columns,
			casesByStatus,
			statusTypes,
			caseType: 'woo',
		})
		expect(out.columns.map((col) => col.id)).toEqual([
			'Ontvangen',
			'In behandeling',
		])
		expect(out.casesByStatus).toEqual({
			Ontvangen: [{ id: '1', caseType: 'woo' }],
			'In behandeling': [{ id: '3', caseType: 'woo' }],
		})
	})

	it('never draws a final status as a column, even when the type declares one', () => {
		const out = narrowBoard({
			columns: [...columns, { id: 'Afgehandeld', name: 'Afgehandeld' }],
			casesByStatus,
			statusTypes,
			caseType: 'woo',
		})
		expect(out.columns.map((col) => col.id)).not.toContain('Afgehandeld')
	})

	it('leaves the merged board as it was', () => {
		narrowBoard({ columns, casesByStatus, statusTypes, caseType: 'bezwaar' })
		expect(casesByStatus.Ontvangen).toHaveLength(2)
		expect(columns).toHaveLength(3)
	})
})

describe('mergeColumnBack', () => {
	it('is the drawn list when the board is not narrowed', () => {
		const visible = [
			{ id: '2', caseType: 'bezwaar' },
			{ id: '1', caseType: 'woo' },
		]
		expect(mergeColumnBack(casesByStatus.Ontvangen, visible, '')).toBe(visible)
	})

	it('keeps the cases of the other types in the column when a narrowed list comes back', () => {
		// The woo card was reordered (or dropped here) while the board showed
		// woo only; the bezwaar case in the same column must not be lost.
		const merged = [
			{ id: '1', caseType: 'woo' },
			{ id: '2', caseType: 'bezwaar' },
			{ id: '5', caseType: 'woo' },
		]
		const visible = [
			{ id: '5', caseType: 'woo' },
			{ id: '1', caseType: 'woo' },
		]
		expect(mergeColumnBack(merged, visible, 'woo')).toEqual([
			{ id: '5', caseType: 'woo' },
			{ id: '1', caseType: 'woo' },
			{ id: '2', caseType: 'bezwaar' },
		])
	})
})

describe('the board', () => {
	it('draws the narrowed columns and writes a column back through the merge', () => {
		expect(boardSource).toContain('v-for="col in visibleColumns"')
		expect(boardSource).toContain(':cases="visibleCasesByStatus[col.id] || []"')
		expect(boardSource).toContain('mergeColumnBack(')
		// A visible label beside the select (DqWerkbord "Zaaktype"), and the
		// combobox named for assistive technology.
		expect(boardSource).toContain(
			":ariaLabelCombobox=\"t('dossiq', 'Case type')\"",
		)
		expect(boardSource).toContain('workflow-board__case-type-label')
	})

	it('clears the navigation toggle: the title starts 56px in, like the library pages', () => {
		// The board owns its header. The library's dashboard header pads
		// 56px for Nextcloud's navigation toggle; the board pads 16px itself,
		// so the header adds 40.
		expect(boardSource).toMatch(
			/\.workflow-board__header\s*\{[^}]*padding-inline-start:\s*40px/,
		)
	})
})
