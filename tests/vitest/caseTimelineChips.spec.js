// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The History tab's kind chips (board DqZaakHistorie): All with the total,
 * then every kind the case has entries of, each with its count; choosing
 * one filters the list without reading it again.
 *
 * @spec openspec/specs/case-history-surface/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	translate: (app, text, vars) => String(text).replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m)),
	translatePlural: (app, one) => one,
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', async (importOriginal) => ({ ...(await importOriginal()), generateUrl: (u) => u }))

const { default: CaseTimelineTab } = await import('../../src/views/cases/components/CaseTimelineTab.vue')
const { kindChips, kindOptions } = CaseTimelineTab.computed
const { chooseKind, applyKindFilter } = CaseTimelineTab.methods

const ENTRIES = [
	{ id: '1', kind: '' },
	{ id: '2', kind: 'status' },
	{ id: '3', kind: 'status' },
	{ id: '4', kind: 'document' },
]
const KINDS = [
	{ slug: 'status', title: 'Status' },
	{ slug: 'document', title: 'Documents' },
	{ slug: 'task', title: 'Tasks' },
]

/**
 * A component context with the given filter.
 *
 * @param {object|null} kindFilter The chosen kind.
 * @return {object} The context.
 */
function context(kindFilter = null) {
	const ctx = { allEntries: ENTRIES, kinds: KINDS, kindFilter, entries: [], applyKindFilter }
	ctx.kindOptions = kindOptions.call(ctx)
	return ctx
}

describe('the History tab kind chips', () => {
	it('starts with All and lists only the kinds the case has, with counts', () => {
		const chips = kindChips.call(context())
		expect(chips.map((chip) => [chip.label, chip.count, chip.active])).toEqual([
			['All', 4, true],
			['Note', 1, false],
			['Status', 2, false],
			['Documents', 1, false],
		])
	})

	it('exports the entries the chips show as CSV, quotes escaped', () => {
		const ctx = {
			entries: [{ created: '2026-10-04T10:24:00Z', kind: 'status', author: 'Pieter "PJ" Jansen', visibility: 'public', message: 'Status gewijzigd' }],
			kindLabel: () => 'Status',
		}
		const csv = CaseTimelineTab.methods.entriesCsv.call(ctx)
		const [head, row] = csv.split('\r\n')
		expect(head).toBe('"When","Kind","Who","Visibility","Message"')
		expect(row).toBe('"2026-10-04T10:24:00Z","Status","Pieter ""PJ"" Jansen","public","Status gewijzigd"')
	})

	it('marks the chosen kind', () => {
		const chips = kindChips.call(context({ id: 'status' }))
		expect(chips.find((chip) => chip.active).label).toBe('Status')
	})

	it('filters the list without reading it again, and All shows every entry', () => {
		const ctx = context()
		chooseKind.call(ctx, 'status')
		expect(ctx.entries.map((entry) => entry.id)).toEqual(['2', '3'])
		chooseKind.call(ctx, null)
		expect(ctx.kindFilter).toBe(null)
		expect(ctx.entries).toHaveLength(4)
	})
})
