/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the verwerkingen API wrapper (avg-verwerkingenlogging,
 * thin consumer): every call targets OPENREGISTER's /api/avg endpoints
 * (never a procest route), and the response envelopes unwrap correctly.
 *
 * @spec openspec/specs/avg-verwerkingenlogging/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	FALLBACK_ACTIVITY_CODE,
	listVerwerkingsactiviteiten,
	countVerwerkingen,
	fetchBetrokkeneExtract,
} from '../../src/services/verwerkingenApi.js'

const axiosGet = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/axios', () => ({
	default: { get: (...args) => axiosGet(...args) },
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (u) => `/index.php${u}`,
}))

describe('verwerkingenApi (thin consumer of OR)', () => {
	beforeEach(() => {
		axiosGet.mockReset()
	})

	it('exposes the OR flagged-fallback code (OR-PA-4)', () => {
		expect(FALLBACK_ACTIVITY_CODE).toBe('niet-geclassificeerde-verwerking')
	})

	it('lists the catalogue from OpenRegister, never a procest route', async () => {
		axiosGet.mockResolvedValue({
			data: { results: [{ code: 'zaakafhandeling', status: 'draft' }] },
		})
		const activities = await listVerwerkingsactiviteiten()
		expect(axiosGet).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/avg/verwerkingsactiviteiten',
		)
		expect(activities).toHaveLength(1)
		expect(activities[0].code).toBe('zaakafhandeling')
	})

	it('unwraps a bare-array catalogue response too', async () => {
		axiosGet.mockResolvedValue({ data: [{ code: 'behandelen-klacht' }] })
		const activities = await listVerwerkingsactiviteiten()
		expect(activities).toHaveLength(1)
	})

	it('counts log entries via OR with activity + register filters', async () => {
		axiosGet.mockResolvedValue({ data: { count: 7, results: [] } })
		const count = await countVerwerkingen({
			activity: 'uuid-1',
			register: 'reg-1',
		})
		expect(axiosGet).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/avg/verwerkingen',
			{ params: { activity: 'uuid-1', register: 'reg-1' } },
		)
		expect(count).toBe(7)
	})

	it('produces the per-betrokkene extract through OR-PA-7', async () => {
		axiosGet.mockResolvedValue({
			data: { subject: { idType: 'BSN' }, count: 2, reads: [] },
		})
		const extract = await fetchBetrokkeneExtract({
			idType: 'BSN',
			idValue: '999990011',
		})
		expect(axiosGet).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/avg/verwerkingen/betrokkene',
			{ params: { subjectIdType: 'BSN', subjectIdValue: '999990011' } },
		)
		expect(extract.count).toBe(2)
	})
})
