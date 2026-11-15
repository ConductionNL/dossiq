// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Characterisation tests for `moveCaseStatus` (src/utils/caseStatusBoardMoves.js):
 * the reads and the write a board move makes, and every sentence it throws.
 * The transition helpers are stubbed so only this function's flow is pinned.
 *
 * @spec openspec/changes/kanban-board-keyboard-status-transition/specs/dashboard/spec.md#requirement-req-dash-v1-006-workflow-board-view-v1
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockGet = vi.fn()
const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
const mockWarning = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({ showWarning: (...a) => mockWarning(...a) }))
const helpers = {
	findTransitionToStatus: vi.fn(),
	transitionIsBlocked: vi.fn(),
	transitionBlockReason: vi.fn(),
	buildTransitionPayload: vi.fn((o) => ({ payloadFor: o.transitionId })),
	refusalMessage: vi.fn((data, tr) => 'refusal:' + JSON.stringify(data) + ':' + tr('x')),
}
vi.mock('../../src/utils/caseLifecycleHelpers.js', () => ({
	findTransitionToStatus: (...a) => helpers.findTransitionToStatus(...a),
	transitionIsBlocked: (...a) => helpers.transitionIsBlocked(...a),
	transitionBlockReason: (...a) => helpers.transitionBlockReason(...a),
	buildTransitionPayload: (...a) => helpers.buildTransitionPayload(...a),
	refusalMessage: (...a) => helpers.refusalMessage(...a),
}))
const mockFailed = vi.fn()
vi.mock('../../src/utils/transitionOutcome.js', () => ({ failedActionsWarning: (...a) => mockFailed(...a) }))

const { moveCaseStatus } = await import('../../src/utils/caseStatusBoardMoves.js')

/**
 * The message a move throws.
 *
 * @param {object} move The move.
 * @return {Promise<Error>} The error.
 */
async function thrown(move) {
	try {
		await moveCaseStatus(move)
	} catch (e) {
		return e
	}
	throw new Error('nothing thrown')
}

describe('case status board move golden', () => {
	beforeEach(() => {
		mockGet.mockReset()
		mockPost.mockReset()
		mockWarning.mockReset()
		mockFailed.mockReset()
		Object.values(helpers).forEach((fn) => fn.mockClear())
		mockGet.mockResolvedValue({ data: { transitions: [{ id: 't1' }] } })
		helpers.findTransitionToStatus.mockReturnValue({ id: 't1' })
		helpers.transitionIsBlocked.mockReturnValue(false)
		mockFailed.mockReturnValue('')
	})

	it('posts the transition that ends on the status and answers the engine', async () => {
		mockPost.mockResolvedValue({ data: { ok: true } })
		const answer = await moveCaseStatus({ card: { '@self': { id: 'c 1' } }, value: 'st-2', toKey: 'Done' })
		expect(mockGet).toHaveBeenCalledWith('/apps/dossiq/api/case/c%201/available-transitions')
		expect(helpers.findTransitionToStatus).toHaveBeenCalledWith([{ id: 't1' }], 'st-2')
		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/case/c%201/transition', { payloadFor: 't1' })
		expect(answer).toEqual({ ok: true })
		expect(mockWarning).not.toHaveBeenCalled()
	})

	it('warns about failed actions and still answers', async () => {
		mockPost.mockResolvedValue({ data: { ok: 1 } })
		mockFailed.mockReturnValue('Two actions failed.')
		expect(await moveCaseStatus({ card: { id: 'c' }, value: 'v', toKey: 'K' })).toEqual({ ok: 1 })
		expect(mockWarning).toHaveBeenCalledWith('Two actions failed.')
	})

	it('says a move that is not on offer cannot be made from here', async () => {
		helpers.findTransitionToStatus.mockReturnValue(null)
		const error = await thrown({ card: { id: 'c' }, value: 'v', toKey: 'Done' })
		expect(error.message).toBe('You cannot move this case to Done from here.')
		expect(mockPost).not.toHaveBeenCalled()
	})

	it.each([['Needs a document.', 'Needs a document.'], ['', 'Something is holding this case here.']])('says what holds a blocked move (%j)', async (reason, message) => {
		helpers.transitionIsBlocked.mockReturnValue(true)
		helpers.transitionBlockReason.mockReturnValue(reason)
		expect((await thrown({ card: { id: 'c' }, value: 'v', toKey: 'K' })).message).toBe(message)
		expect(mockPost).not.toHaveBeenCalled()
	})

	it.each([
		[{ response: { data: { code: 'no' } } }, 'refusal:{"code":"no"}:x'],
		[new Error('net'), 'refusal:{}:x'],
	])('throws the refusal of a failed write, keeping its cause (%j)', async (failure, message) => {
		mockPost.mockRejectedValue(failure)
		const error = await thrown({ card: { id: 'c' }, value: 'v', toKey: 'K' })
		expect(error.message).toBe(message)
		expect(error.cause).toBe(failure)
	})

	it('reads an offer that is not a list as none', async () => {
		mockGet.mockResolvedValue({ data: {} })
		helpers.findTransitionToStatus.mockReturnValue(null)
		await thrown({ card: null, value: 'v', toKey: 'K' })
		expect(helpers.findTransitionToStatus).toHaveBeenCalledWith([], 'v')
		expect(mockGet).toHaveBeenCalledWith('/apps/dossiq/api/case/undefined/available-transitions')
	})
})
