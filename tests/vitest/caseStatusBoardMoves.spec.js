// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Cases board view's named handlers: a drop moves a case through the
 * offered transition that ends on the dropped status, and the board asks the
 * engine where a case may go. The manifest names both, and the registry
 * exposes both, so the board cannot fall back to a PATCH of `status`.
 *
 * @spec openspec/changes/kanban-board-keyboard-status-transition/specs/dashboard/spec.md#requirement-req-dash-v1-006-workflow-board-view-v1
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url) => url,
}))
vi.mock('@nextcloud/dialogs', () => ({
	showWarning: vi.fn(),
}))

const axios = (await import('@nextcloud/axios')).default
const { caseStatusTargets, moveCaseStatus } =
	await import('../../src/utils/caseStatusBoardMoves.js')

const ROOT = path.resolve(__dirname, '../..')
const OFFER = [
	{ id: 't1', toStatus: 's2' },
	{ id: 't2', toStatus: 's3', guardsPassed: false, failedGuards: [] },
]

describe('caseStatusBoardMoves', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		axios.get.mockResolvedValue({ data: { transitions: OFFER } })
		axios.post.mockResolvedValue({ data: { success: true } })
	})

	it('posts the offered transition that ends on the dropped status', async () => {
		await moveCaseStatus({
			card: { id: 'c1' },
			value: 's2',
			toKey: 'In behandeling',
		})
		expect(axios.get).toHaveBeenCalledWith(
			'/apps/dossiq/api/case/c1/available-transitions',
		)
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/dossiq/api/case/c1/transition',
			expect.objectContaining({ transitionId: 't1' }),
		)
	})

	it('refuses a status nothing on offer ends on, without a write', async () => {
		await expect(
			moveCaseStatus({ card: { id: 'c1' }, value: 's9', toKey: 'Besluit' }),
		).rejects.toThrow('Besluit')
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('answers only the statuses no guard holds', async () => {
		expect(await caseStatusTargets({ card: { id: 'c1' } })).toEqual(['s2'])
	})

	it('the Cases board names both handlers and the registry exposes them', () => {
		const manifest = JSON.parse(
			fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
		)
		const board = manifest.pages.find((page) => page.id === 'Cases').config.board
		expect(board.moveHandler).toBe('moveCaseStatus')
		expect(board.moveTargetsHandler).toBe('caseStatusTargets')
		const registry = fs.readFileSync(
			path.join(ROOT, 'src', 'registry.js'),
			'utf8',
		)
		expect(registry).toMatch(
			/moveCaseStatus: \{\s*kind: 'handler',\s*handler: moveCaseStatus/,
		)
		expect(registry).toMatch(
			/caseStatusTargets: \{\s*kind: 'handler',\s*handler: caseStatusTargets/,
		)
	})
})
