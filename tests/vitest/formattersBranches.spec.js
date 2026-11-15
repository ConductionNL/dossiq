// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The branches of the cell formatters that read counts, processing times,
 * penalties, outbound messages, sub-case badges and looked-up labels: each
 * edge a row can carry, pinned so the formatters keep answering the same.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const objectStore = {
	collections: {},
	objectTypeRegistry: { statusType: {} },
	fetchCollectionForOptions: vi.fn(() => Promise.resolve([])),
}

let deelzaakStore = { subCaseCounts: {}, fetchSubCaseCounts: vi.fn(() => Promise.resolve()) }
let deelzaakThrows = false

vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore }))
vi.mock('../../src/store/modules/deelzaak.js', () => ({
	useDeelzaakStore: () => {
		if (deelzaakThrows) {
			throw new Error('no pinia')
		}
		return deelzaakStore
	},
}))

const { default: formatters, resetRelatedLabels } = await import('../../src/services/formatters.js')

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('counts', () => {
	it.each([
		[undefined, ''],
		['', ''],
		[-1, ''],
		[1.5, ''],
		['3', '%n case types'],
		[0, '%n case types'],
	])('caseTypeCount(%s) is "%s"', (value, phrase) => {
		expect(formatters.caseTypeCount(value)).toBe(phrase)
	})

	it.each([
		[undefined, ''],
		['', ''],
		[-2, ''],
		['x', ''],
		['2', '%n properties'],
		[0, '%n properties'],
	])('propertyCount(%s) is "%s"', (value, phrase) => {
		expect(formatters.propertyCount(value)).toBe(phrase)
	})
})

describe('processingTime', () => {
	it.each([
		['P0D', undefined, 'P'],
		['P1M14D', undefined, '1 month, 14 days'],
		['P1M', { extensionPeriod: 'P2M14D' }, '1 month, +2 months, 14 days'],
		['Termijn', { extensionPeriod: 'P2M' }, 'Termijn, +2 months'],
		['P2W', { extensionPeriod: 'P7D' }, '2 weeks, +1'],
		['P14D', { extensionPeriod: 'P1W3D' }, '2 weeks, +1 week, 3 days'],
		['P14D', { extensionPeriod: 'later' }, '2 weeks, +later'],
		['P14D', { extensionAllowed: true, extensionPeriod: '' }, '2 weeks'],
		['P14D', null, '2 weeks'],
	])('processingTime(%s, %j) is "%s"', (value, row, words) => {
		expect(formatters.processingTime(value, row)).toBe(words)
	})
})

describe('penaltyLine', () => {
	it('reads a running penalty without a start day as the bare amount', () => {
		const running = formatters.penaltyLine('running', { penaltyEuros: 'x' })
		expect(running).toMatch(/0/)
		expect(running).not.toMatch(/running since/)
		expect(formatters.penaltyLine('running', null)).toBe(running)
	})

	it('reads an unknown state, and no state, as none', () => {
		expect(formatters.penaltyLine('other', {})).toBe('None')
		expect(formatters.penaltyLine(undefined)).toBe('None')
	})

	it('reads a fixed and a running penalty with their words', () => {
		expect(formatters.penaltyLine('definitive', { penaltyEuros: 12 })).toMatch(/12.*, fixed$/)
		expect(formatters.penaltyLine('running', { penaltyEuros: 12, penaltySince: '2026-10-16' })).toMatch(/12.*, running since /)
	})
})

describe('outboundMessages', () => {
	it('keeps an unknown moment and channel as they are, and skips what is not an entry', () => {
		expect(formatters.outboundMessages([null, 'x', { moment: 'custom', channel: 'fax' }])).toBe('custom via fax')
	})

	it('leaves the time out when it is missing or not a date', () => {
		expect(formatters.outboundMessages([
			{ moment: 'transfer', channel: 'digital-post', sentAt: 'not a date' },
			{ channel: 'email' },
		])).toBe('Transfer via digital post;  via email')
	})

	it('marks an entry without a channel as not sent, whatever its time', () => {
		expect(formatters.outboundMessages([{ moment: 'extension', sentAt: '2026-10-03T12:32:00Z' }])).toBe('Extension, not sent')
		expect(formatters.outboundMessages('text')).toBe('')
	})
})

describe('subCaseCount', () => {
	beforeEach(() => {
		deelzaakThrows = false
		deelzaakStore = { subCaseCounts: {}, fetchSubCaseCounts: vi.fn(() => Promise.resolve()) }
	})

	it('draws nothing without an id, without a store, or for a sub-case', () => {
		expect(formatters.subCaseCount(undefined, null)).toBe('')
		expect(formatters.subCaseCount('', {})).toBe('')
		expect(formatters.subCaseCount(undefined, { id: 'c-1', parentCase: 'p-1' })).toBe('')
		deelzaakThrows = true
		expect(formatters.subCaseCount(undefined, { id: 'c-1' })).toBe('')
	})

	it('reads the count by the row id, its @self id or the value, and queues the uncounted', async () => {
		deelzaakStore.subCaseCounts = { 'c-1': 2, 'c-2': 3, 'c-3': 4 }
		const two = formatters.subCaseCount(undefined, { id: 'c-1' })
		expect(two).not.toBe('')
		expect(formatters.subCaseCount(undefined, { '@self': { id: 'c-2' } })).not.toBe(two)
		expect(formatters.subCaseCount('c-3', {})).not.toBe(two)
		expect(formatters.subCaseCount('c-4', null)).toBe('')
		await flush()
		expect(deelzaakStore.fetchSubCaseCounts).toHaveBeenCalledWith(['c-4'])
	})
})

describe('the own label lookup', () => {
	beforeEach(() => {
		resetRelatedLabels()
		objectStore.fetchCollectionForOptions.mockReset()
	})

	it('keeps rows with an id and a title or name, by their id or @self id', async () => {
		objectStore.fetchCollectionForOptions.mockResolvedValue([
			{ id: 's-1', title: 'Open', name: 'open' },
			{ '@self': { id: 's-2' }, name: 'Closed' },
			{ id: 's-3' },
			{ title: 'No id' },
			null,
		])
		expect(formatters.statusTypeName('s-1')).toBe('s-1')
		await flush()
		expect(formatters.statusTypeName('s-1')).toBe('Open')
		expect(formatters.statusTypeName('s-2')).toBe('Closed')
		expect(formatters.statusTypeName('s-3')).toBe('s-3')
		expect(objectStore.fetchCollectionForOptions).toHaveBeenCalledTimes(1)
	})

	it('looks up again after an answer that is not a list', async () => {
		objectStore.fetchCollectionForOptions.mockResolvedValue({ rows: [] })
		formatters.statusTypeName('s-9')
		await flush()
		formatters.statusTypeName('s-9')
		expect(objectStore.fetchCollectionForOptions).toHaveBeenCalledTimes(2)
	})
})
