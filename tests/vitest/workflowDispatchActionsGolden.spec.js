/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the workflow store's `dispatchActions`
 * (src/store/modules/workflow.js): which dispatcher each action type reaches,
 * with which arguments, in order, and the result row for each outcome.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({ saveObject: vi.fn() }),
}))

vi.mock('../../src/store/modules/engineTask.js', () => ({
	useEngineTaskStore: () => ({ create: vi.fn(), error: null }),
}))

vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	n: (app, text) => text,
}))

const { useWorkflowStore } = await import('../../src/store/modules/workflow.js')

const DISPATCHERS = [
	'dispatchEmailAction',
	'dispatchCreateTaskAction',
	'dispatchCreateSubCaseAction',
	'dispatchWebhookAction',
	'dispatchSetFieldAction',
	'dispatchNotifyAction',
]

describe('workflow dispatchActions golden', () => {
	let store
	let calls
	let logged

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useWorkflowStore()
		calls = []
		for (const name of DISPATCHERS) {
			vi.spyOn(store, name).mockImplementation(async (...args) => {
				calls.push([name, ...args])
				if (args[0].fail) {
					throw new Error(args[0].fail)
				}
			})
		}
		logged = vi.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		logged.mockRestore()
	})

	it('reaches each dispatcher with its own arguments, in order', async () => {
		const caseData = { id: 'case-1' }
		const transition = { id: 't-1' }
		const actions = [
			{ type: 'sendEmail' },
			{ type: 'createTask' },
			{ type: 'createSubCase' },
			{ type: 'webhook' },
			{ type: 'setField' },
			{ type: 'notify' },
		]
		const results = await store.dispatchActions(actions, caseData, transition)
		expect(calls).toEqual([
			['dispatchEmailAction', actions[0], caseData, transition],
			['dispatchCreateTaskAction', actions[1], caseData],
			['dispatchCreateSubCaseAction', actions[2], caseData],
			['dispatchWebhookAction', actions[3], caseData, transition],
			['dispatchSetFieldAction', actions[4], caseData],
			['dispatchNotifyAction', actions[5], caseData],
		])
		expect(results).toEqual(actions.map((action) => ({ action: action.type, success: true })))
		expect(logged).not.toHaveBeenCalled()
	})

	it('answers an unknown type, and a failure, and carries on', async () => {
		const results = await store.dispatchActions(
			[{ type: 'teleport' }, { type: 'toString' }, { type: 'webhook', fail: 'down' }, { type: 'notify' }],
			{ id: 'c' },
			null,
		)
		expect(results).toEqual([
			{ action: 'teleport', success: false, error: 'Unknown action type' },
			{ action: 'toString', success: false, error: 'Unknown action type' },
			{ action: 'webhook', success: false, error: 'down' },
			{ action: 'notify', success: true },
		])
		expect(logged).toHaveBeenCalledTimes(1)
		expect(logged.mock.calls[0][0]).toBe('Workflow action webhook failed:')
		expect(logged.mock.calls[0][1]).toEqual(new Error('down'))
	})

	it('answers nothing for no actions', async () => {
		expect(await store.dispatchActions([], {}, {})).toEqual([])
	})
})
