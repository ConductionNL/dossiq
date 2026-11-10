/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation golden for `applyPageOverlay` (src/utils/structureProfile.js):
 * every step of one overlay together, in its fixed order, and the edges of
 * each step.
 *
 * @spec openspec/specs/nav-dedup-and-grouping/spec.md#REQ-PNDG-008
 */

import { describe, expect, it } from 'vitest'
import { applyPageOverlay } from '../../src/utils/structureProfile.js'

const PAGE = {
	id: 'P',
	title: 'Old',
	slots: { a: 'A' },
	config: {
		pane: { on: true },
		keep: 1,
		columns: ['title', { key: 'status', width: 1 }, { key: 'owner' }, 'gone'],
		actions: [{ id: 'one' }, { id: 'two' }, { id: 'three' }],
		defaultSavedViews: [{ slug: 'mine', overrides: { a: 1, b: { c: 2 } } }],
		notAList: 'text',
	},
}

describe('applyPageOverlay golden', () => {
	it('replaces, patches, appends and orders, in that order', () => {
		const out = applyPageOverlay(PAGE, {
			id: 'P',
			title: 'New',
			slots: { b: 'B' },
			config: { pane: null, added: true, actions: [{ id: 'one' }, { id: 'two' }, { id: 'three' }, { id: 'four' }] },
			configPatch: {
				columns: { gone: null, status: { width: 2 }, title: { ignored: true } },
				defaultSavedViews: { mine: { overrides: { b: { d: 3 } } } },
				missing: { x: { y: 1 } },
				notAList: { x: {} },
				empty: null,
			},
			configAppend: { actions: [{ id: 'five' }], fresh: 'not a list', notAList: [{ id: 'x' }] },
			configOrder: { actions: ['four', 'nope', 'one'], columns: 'not a list', unknown: ['a'] },
		})
		expect(out).toEqual({
			id: 'P',
			title: 'New',
			slots: { a: 'A', b: 'B' },
			config: {
				keep: 1,
				columns: ['title', { key: 'status', width: 2 }, { key: 'owner' }],
				actions: [{ id: 'four' }, { id: 'one' }, { id: 'two' }, { id: 'three' }, { id: 'five' }],
				defaultSavedViews: [{ slug: 'mine', overrides: { a: 1, b: { c: 2, d: 3 } } }],
				notAList: [{ id: 'x' }],
				added: true,
				missing: [],
				empty: [],
				fresh: [],
				unknown: [],
			},
		})
		expect(Object.keys(out.config)).toEqual([
			'keep', 'columns', 'actions', 'defaultSavedViews', 'notAList', 'added', 'missing', 'empty', 'fresh', 'unknown',
		])
		expect(Object.keys(out)).toEqual(['id', 'title', 'slots', 'config'])
		expect(PAGE.config.pane).toEqual({ on: true })
		expect(PAGE.config.columns).toHaveLength(4)
	})

	it('keeps the page title and slots when the overlay sets none', () => {
		const out = applyPageOverlay(PAGE, { id: 'P', title: '' })
		expect(out.title).toBe('Old')
		expect(out.slots).toBe(PAGE.slots)
		expect(out.config).toEqual(PAGE.config)
		expect(out.config).not.toBe(PAGE.config)
		expect(Object.keys(out)).toEqual(['id', 'title', 'slots', 'config'])
	})

	it('reads a page without config or slots', () => {
		expect(applyPageOverlay({ id: 'Q' }, { id: 'Q', slots: { s: 1 }, title: 7 })).toEqual({ id: 'Q', config: {}, slots: { s: 1 } })
		expect(applyPageOverlay({ id: 'Q' }, { id: 'Q', slots: 'x' })).toEqual({ id: 'Q', config: {} })
	})
})
