// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * `searchIndexStatus()` turns openregister's last-run REPORT into a time.
 *
 * 🔴 OPENREGISTER ANSWERS `lastRun` AS AN OBJECT, OR `[]` WHEN NOTHING RAN.
 * `SearchIndexMaintenance::lastRun()` returns the stored report array. The old
 * client passed it straight through and the panel printed it with `String()`:
 * `String([])` is the empty string, so "Last run" stood empty on the cloud
 * (round-4 check), and a real report would have read "[object Object]". These
 * tests feed the shapes openregister actually sends.
 *
 * @spec openspec/changes/r5-admin-settings-and-tour-tell-the-truth/specs/admin-settings/spec.md
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

let answer = {}

vi.mock('@nextcloud/axios', () => ({
	default: { get: () => Promise.resolve({ data: answer }) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

const { lastRunTime, searchIndexStatus } =
	await import('../../src/services/searchIndexApi.js')

const BASE = {
	concurrentRebuildSupported: true,
	tables: {},
	tableCount: 0,
	indexCount: 0,
}

beforeEach(() => {
	answer = { ...BASE }
})

describe('searchIndexStatus lastRun', () => {
	it('reads a never-run answer ([]) as null, so the panel says Never', async () => {
		answer = { ...BASE, lastRun: [] }

		expect((await searchIndexStatus()).lastRun).toBeNull()
	})

	it('reads a finished report as its finish time', async () => {
		answer = {
			...BASE,
			lastRun: {
				startedAt: '2026-10-01T02:00:00+00:00',
				finishedAt: '2026-10-01T02:03:00+00:00',
				state: 'done',
				tables: 3,
			},
		}

		expect((await searchIndexStatus()).lastRun).toBe('2026-10-01T02:03:00+00:00')
	})

	it('reads a refused report, which has no finish time, as its start time', async () => {
		answer = {
			...BASE,
			lastRun: { startedAt: '2026-10-01T02:00:00+00:00', state: 'refused' },
		}

		expect((await searchIndexStatus()).lastRun).toBe('2026-10-01T02:00:00+00:00')
	})
})

describe('lastRunTime', () => {
	it.each([[null], [undefined], [''], [[]], [{}], [{ state: 'done' }]])(
		'answers null for %j',
		(report) => {
			expect(lastRunTime(report)).toBeNull()
		},
	)
})
