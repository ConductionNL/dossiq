// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * A workflow's `createTask` action writes an ENGINE task.
 *
 * It used to write a `caseTask` object through `saveObject`, and that is
 * the shape of defect worth a spec of its own: the write succeeded, the
 * transition reported success, and the task was invisible to every surface
 * that shows one. The case pane, the two dashboard widgets, the sidebar
 * list and the work queue all read the engine, so an object write produces
 * a task nobody ever sees and no error anywhere.
 *
 * `saveObject` is asserted NEVER-CALLED in every test rather than only the
 * first. It is the defect's fingerprint, and a later edit reintroducing it
 * on one branch of the handler would otherwise still pass here.
 *
 * The refusal test is the other half. `dispatchActions` records a per-action
 * result and the caller shows it, so a write that failed quietly would be
 * reported to the user as a completed transition.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const saveObject = vi.fn()
const create = vi.fn()

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({ saveObject }),
}))

vi.mock('../../src/store/modules/engineTask.js', () => ({
	useEngineTaskStore: () => ({ create, error: null }),
}))

vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	n: (app, text) => text,
}))

const { useWorkflowStore } = await import('../../src/store/modules/workflow.js')

beforeEach(() => {
	setActivePinia(createPinia())
	saveObject.mockReset()
	create.mockReset()
	create.mockResolvedValue({ uuid: 'engine-1' })
})

describe('dispatchCreateTaskAction', () => {
	it('writes the task to the engine and never to the caseTask schema', async () => {
		await useWorkflowStore().dispatchCreateTaskAction(
			{
				type: 'createTask',
				title: 'Meetrapport toezicht opvragen',
				description: 'Vraag het rapport op bij de toezichthouder',
				priority: 'high',
				assignee: 'admin',
			},
			{ id: 'case-9' },
		)

		expect(saveObject).not.toHaveBeenCalled()
		expect(create).toHaveBeenCalledTimes(1)
		expect(create).toHaveBeenCalledWith({
			title: 'Meetrapport toezicht opvragen',
			description: 'Vraag het rapport op bij de toezichthouder',
			case: 'case-9',
			status: 'available',
			priority: 'high',
			assignee: 'admin',
		})
	})

	it('anchors the task on the case the transition ran against', async () => {
		await useWorkflowStore().dispatchCreateTaskAction(
			{ type: 'createTask', title: 'Stuk opvragen' },
			{ id: 'case-4' },
		)

		expect(saveObject).not.toHaveBeenCalled()
		expect(create.mock.calls[0][0].case).toBe('case-4')
	})

	it('falls back to a title, a normal priority and no assignee', async () => {
		await useWorkflowStore().dispatchCreateTaskAction(
			{ type: 'createTask' },
			{ id: 'case-4' },
		)

		expect(saveObject).not.toHaveBeenCalled()
		const payload = create.mock.calls[0][0]
		expect(payload.title).toBe('New task')
		expect(payload.priority).toBe('normal')
		// NOT null. The engine's create payload drops empty strings and
		// would send a literal "null" for a null assignee.
		expect(payload.assignee).toBe('')
	})

	it('throws when the engine refuses, so the action is reported as failed', async () => {
		create.mockResolvedValue(null)

		await expect(
			useWorkflowStore().dispatchCreateTaskAction(
				{ type: 'createTask', title: 'Stuk opvragen' },
				{ id: 'case-4' },
			),
		).rejects.toThrow()

		expect(saveObject).not.toHaveBeenCalled()
	})
})
