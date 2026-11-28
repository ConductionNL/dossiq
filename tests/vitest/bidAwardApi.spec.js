/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The calls behind proposing an award: the scoring sheet is read from the
 * case's route and normalised, a bid transition goes to OpenRegister's
 * transition endpoint, and a refusal keeps the server's own words.
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { readScoringSheet, refusalOf, walkBidTransition } from '../../src/services/bidAwardApi.js'

const mockGet = vi.fn()
const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...a) => mockGet(...a),
		post: (...a) => mockPost(...a),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))

describe('bidAwardApi', () => {
	beforeEach(() => {
		mockGet.mockReset()
		mockPost.mockReset()
	})

	it('reads the scoring sheet of the case', async () => {
		mockGet.mockResolvedValue({ data: { submissions: [{ id: 'b1' }], refusal: 'no formula' } })
		const sheet = await readScoringSheet('c 1')
		expect(mockGet).toHaveBeenCalledWith('/apps/dossiq/api/cases/c%201/scoring')
		expect(sheet).toEqual({ submissions: [{ id: 'b1' }], refusal: 'no formula' })
	})

	it('answers an empty sheet when the server sends no list', async () => {
		mockGet.mockResolvedValue({ data: { submissions: null } })
		expect(await readScoringSheet('c1')).toEqual({ submissions: [], refusal: '' })
	})

	it('walks a bid transition on OpenRegister', async () => {
		mockPost.mockResolvedValue({ data: {} })
		await walkBidTransition('b/1', 'proposeAward')
		expect(mockPost).toHaveBeenCalledWith('/apps/openregister/api/objects/b%2F1/transition', { action: 'proposeAward' })
	})

	it('keeps the refusal as the server words it, else the fallback', () => {
		expect(refusalOf({ response: { data: { message: 'Closed' } } }, 'x')).toBe('Closed')
		expect(refusalOf({ response: { data: { error: 'Nope' } } }, 'x')).toBe('Nope')
		expect(refusalOf(new Error('net'), 'fallback')).toBe('fallback')
	})
})
