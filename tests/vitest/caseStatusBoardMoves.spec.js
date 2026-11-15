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
const { showWarning } = await import('@nextcloud/dialogs')
const { DEFAULT_STATUS_COLOUR, STATUS_COLOURS, statusColourToken } =
	await import('../../src/utils/statusColour.js')
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
		showWarning.mockReset()
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

	it('tells the handler when automatic actions did not run', async () => {
		axios.post.mockResolvedValue({
			data: {
				status: 'partial',
				failedActions: [
					{ type: 'createTask', error: 'no_actor' },
					{ type: 'sendEmail', error: 'no_address' },
				],
			},
		})
		await moveCaseStatus({
			card: { id: 'c1' },
			value: 's2',
			toKey: 'In behandeling',
		})
		expect(showWarning).toHaveBeenCalledTimes(1)
		expect(showWarning.mock.calls[0][0]).toContain('2')
	})

	it('refuses a status nothing on offer ends on, without a write', async () => {
		await expect(
			moveCaseStatus({ card: { id: 'c1' }, value: 's9', toKey: 'Besluit' }),
		).rejects.toThrow('Besluit')
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('answers each target once, a held one not allowed with its reason', async () => {
		axios.get.mockResolvedValue({
			data: {
				transitions: [
					{ id: 't1', toStatus: 's2' },
					{
						id: 't2',
						toStatus: 's3',
						guardsPassed: false,
						failedGuards: [
							{ failureMessage: 'Add the decision first.' },
						],
					},
					{ id: 't3', toStatus: 's3' },
					{
						id: 't4',
						toStatus: 's4',
						guardsPassed: false,
						failedGuards: [],
					},
				],
			},
		})
		expect(await caseStatusTargets({ card: { id: 'c1' } })).toEqual([
			{ key: 's2', allowed: true, reason: '' },
			{ key: 's3', allowed: false, reason: 'Add the decision first.' },
			{
				key: 's4',
				allowed: false,
				reason: 'Something is holding this case here.',
			},
		])
	})

	it('the Cases board names both handlers and the registry exposes them', () => {
		const manifest = JSON.parse(
			fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
		)
		const board = manifest.pages.find((page) => page.id === 'Cases').config.board
		expect(board.moveHandler).toBe('moveCaseStatus')
		expect(board.moveTargetsHandler).toBe('caseStatusTargets')
		const status = board.columnFields.find((column) => column.field === 'status')
		expect(status.source.capacityField).toBe('capacity')
		// A stored grey is the schema default, not a choice, so it never wins
		// a merged column over a chosen hue; each hue draws as the same token
		// the status badge uses.
		expect(status.source.neutralColor).toBe(DEFAULT_STATUS_COLOUR)
		expect(status.source.colorMap).toEqual(
			Object.fromEntries(
				STATUS_COLOURS.map((hue) => [hue, statusColourToken(hue)]),
			),
		)
		expect(status.source.filter).toEqual({ isFinal: false })
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
