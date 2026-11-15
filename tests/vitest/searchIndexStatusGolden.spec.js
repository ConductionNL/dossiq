// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation tests for `searchIndexStatus`
 * (src/services/searchIndexApi.js): which answers it refuses, with which
 * message, and how it reads each field of the others.
 *
 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
 */
import { describe, expect, it, vi } from 'vitest'

const mockGet = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => mockGet(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

const { lastRunSeconds, lastRunTime, searchIndexStatus } = await import('../../src/services/searchIndexApi.js')

describe('search index status golden', () => {
	it.each([
		[null, 'The search index status could not be read.'],
		['text', 'The search index status could not be read.'],
		[7, 'The search index status could not be read.'],
		[{ error: 'Not an admin.' }, 'Not an admin.'],
		[{ error: null }, 'The search index status could not be read.'],
		[{ error: 0 }, '0'],
	])('refuses %j with %j', async (data, message) => {
		mockGet.mockResolvedValue({ data })
		await expect(searchIndexStatus()).rejects.toThrow(new Error(message))
		expect(mockGet).toHaveBeenCalledWith('/apps/openregister/api/settings/search-index')
	})

	it('reads an empty answer with every fallback', async () => {
		mockGet.mockResolvedValue({ data: {} })
		expect(await searchIndexStatus()).toEqual({
			concurrentRebuildSupported: false,
			tables: {},
			tableCount: 0,
			indexCount: 0,
			lastRun: null,
			lastRunSeconds: null,
			rowsInTables: null,
			rowsInIndex: null,
			indexed: null,
		})
	})

	it('reads a full answer', async () => {
		mockGet.mockResolvedValue({
			data: {
				concurrentRebuildSupported: true,
				tables: { a: 1 },
				tableCount: '3',
				indexCount: 4,
				rowsInTables: '10',
				rowsInIndex: 0,
				indexed: { a: 2 },
			},
		})
		expect(await searchIndexStatus()).toMatchObject({
			concurrentRebuildSupported: true,
			tables: { a: 1 },
			tableCount: 3,
			indexCount: 4,
			rowsInTables: 10,
			rowsInIndex: 0,
			indexed: { a: 2 },
		})
	})

	it.each([
		[{ tables: 'x', indexed: [1] }],
		[{ tables: null, indexed: 'x' }],
		[{ tables: 0, indexed: null }],
	])('reads tables and indexed that are not objects as their fallbacks (%j)', async (data) => {
		mockGet.mockResolvedValue({ data })
		const status = await searchIndexStatus()
		expect(status.tables).toEqual({})
		expect(status.indexed).toBeNull()
	})

	it('keeps a tables list as it is', async () => {
		mockGet.mockResolvedValue({ data: { tables: [1] } })
		expect((await searchIndexStatus()).tables).toEqual([1])
	})
})

describe('last run golden', () => {
	it.each([
		[undefined, null],
		[null, null],
		['', null],
		['2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z'],
		[[], null],
		[7, null],
		[{}, null],
		[{ startedAt: 'S' }, 'S'],
		[{ startedAt: 'S', finishedAt: 'F' }, 'F'],
		[{ startedAt: 'S', finishedAt: '' }, 'S'],
		[{ startedAt: 5 }, null],
	])('reads the time of %j as %j', (report, time) => {
		expect(lastRunTime(report)).toBe(time)
	})

	it.each([
		[null, null],
		['x', null],
		[[], null],
		[{}, null],
		[{ startedAt: '2026-01-01T00:00:00Z' }, null],
		[{ startedAt: 'nope', finishedAt: '2026-01-01T00:00:05Z' }, null],
		[{ startedAt: '2026-01-01T00:00:05Z', finishedAt: '2026-01-01T00:00:00Z' }, null],
		[{ startedAt: '2026-01-01T00:00:00Z', finishedAt: '2026-01-01T00:00:00Z' }, 0],
		[{ startedAt: '2026-01-01T00:00:00Z', finishedAt: '2026-01-01T00:00:02.600Z' }, 3],
	])('reads the duration of %j as %j', (report, seconds) => {
		expect(lastRunSeconds(report)).toBe(seconds)
	})
})
