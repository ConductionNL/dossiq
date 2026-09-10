// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Dossiq's read side onto OpenRegister's task engine.
 *
 * Four behaviours only observable here:
 *
 *  1. NOTHING TRANSLATES a state or a priority. The engine's vocabulary is
 *     the one caseTask used, so a mapping table would silently diverge the
 *     two the first time either side gained a value.
 *  2. Terminality comes from the engine's own `isTerminal`, not from
 *     re-deriving it off the state. The two can disagree the moment the
 *     engine adds a state, and the engine is right.
 *  3. The case view is `scope: 'all'`, not the caller's assigned set.
 *     Scoping to the reader hides a colleague's task and makes a case look
 *     finished when it is not.
 *  4. A failure is SURFACED. An empty list with no trace of why is
 *     indistinguishable from a genuinely empty one, and that is the failure
 *     shape this migration keeps producing.
 *
 * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
 */
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
const post = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))

const { useEngineTaskStore, isTerminal, TERMINAL_STATES, asTaskRow } =
	await import('../../src/store/modules/engineTask.js')

beforeEach(() => {
	setActivePinia(createPinia())
	get.mockReset()
	post.mockReset()
})

describe('isTerminal', () => {
	it('prefers the engine own flag over re-deriving it from the state', () => {
		// The engine materialises `isTerminal` on write. Re-deriving it here
		// would disagree with the engine the moment it adds a state, and the
		// engine is the one that decides.
		expect(isTerminal({ state: 'active', isTerminal: true })).toBe(true)
		expect(isTerminal({ state: 'completed', isTerminal: false })).toBe(false)
	})

	it('falls back to the shared vocabulary when the row carries no flag', () => {
		for (const state of TERMINAL_STATES) {
			expect(isTerminal({ state })).toBe(true)
		}
		expect(isTerminal({ state: 'active' })).toBe(false)
		expect(isTerminal({ state: 'available' })).toBe(false)
		expect(isTerminal(null)).toBe(false)
	})

	it('declares the same three states caseTask called final', () => {
		// Same vocabulary, deliberately. If this ever needs a mapping table
		// the migration has gone wrong.
		expect([...TERMINAL_STATES]).toEqual(['completed', 'terminated', 'disabled'])
	})
})

describe('useEngineTaskStore', () => {
	it('lists tasks and records the datastore total, not the page size', async () => {
		get.mockResolvedValue({ data: { results: [{ uuid: 'a' }], total: 42 } })
		const store = useEngineTaskStore()

		const rows = await store.list({ limit: 1 })

		// `id` comes along because the read path maps engine rows into the
		// names the components read; the row itself is otherwise untouched.
		expect(rows).toEqual([{ uuid: 'a', id: 'a' }])
		// 42, not 1: a page of one out of forty-two is not a total of one.
		expect(store.total).toBe(42)
	})

	it('asks for the whole case, not the reader own assigned set', async () => {
		get.mockResolvedValue({ data: { results: [], total: 0 } })
		const store = useEngineTaskStore()

		await store.openForCase('case-9')

		const params = get.mock.calls[0][1].params
		expect(params.objectUuid).toBe('case-9')
		// Scoping to the caller hides a colleague's task and makes the case
		// look finished when it is not.
		expect(params.scope).toBe('all')
	})

	it('drops the finished tasks from the case view', async () => {
		get.mockResolvedValue({
			data: {
				results: [
					{ uuid: 'open', state: 'active' },
					{ uuid: 'done', state: 'completed' },
					{ uuid: 'flagged', state: 'active', isTerminal: true },
				],
				total: 3,
			},
		})
		const store = useEngineTaskStore()

		const open = await store.openForCase('case-9')

		expect(open.map((t) => t.uuid)).toEqual(['open'])
	})

	it('reads one task by uuid', async () => {
		get.mockResolvedValue({
			data: { uuid: 't1', title: 'Ask the applicant', state: 'active' },
		})
		const store = useEngineTaskStore()

		const task = await store.fetch('t1')

		expect(task.title).toBe('Ask the applicant')
		// No translation on the way through.
		expect(task.state).toBe('active')
		expect(get.mock.calls[0][0]).toContain('/flow-tasks/t1')
	})

	it('🔴 corrects the out-of-enum status four call sites were writing', async () => {
		post.mockResolvedValue({ data: { uuid: 't1', state: 'available' } })
		const store = useEngineTaskStore()

		await store.create({
			title: 'Advies uitbrengen',
			case: 'case-9',
			status: 'open',
		})

		// `open` is out of enum on BOTH stores: caseTask declared
		// available|active|completed|terminated|disabled and Task::STATES
		// declares the same five. Every task those four call sites created
		// was born in a state no transition could advance. CreateTaskHandler
		// had the same bug and was fixed in #1326; these four were missed,
		// which is what a concept duplicated across five call sites costs.
		expect(post.mock.calls[0][1].state).toBe('available')
	})

	it('keeps a status the engine does accept', async () => {
		post.mockResolvedValue({ data: { uuid: 't1' } })
		const store = useEngineTaskStore()

		await store.create({ title: 'T', status: 'active' })
		expect(post.mock.calls[0][1].state).toBe('active')

		post.mockClear()
		await store.create({ title: 'T' })
		expect(post.mock.calls[0][1].state).toBe('available')
	})

	it('maps the dossiq shape so the call sites need not learn the engine one', async () => {
		post.mockResolvedValue({ data: { uuid: 't1' } })
		const store = useEngineTaskStore()

		await store.create({
			title: 'Hercontrole uitvoeren',
			case: 'case-9',
			description: 'Termijn verlopen',
			assignee: 'k.dijkstra',
			dueDate: '2026-11-02',
		})

		const sent = post.mock.calls[0][1]
		expect(sent.objectUuid).toBe('case-9')
		expect(sent.dueAt).toBe('2026-11-02')
		expect(sent.assignee).toBe('k.dijkstra')
		expect(sent.appId).toBe('dossiq')
		// Absent properties are omitted, never sent empty: OpenRegister
		// refuses an empty object property.
		expect(sent).not.toHaveProperty('priority')
	})

	it('invokes a lifecycle verb and lets the engine decide', async () => {
		post.mockResolvedValue({ data: { uuid: 't1', state: 'completed' } })
		const store = useEngineTaskStore()

		const task = await store.invoke('t1', 'complete', { comment: 'done' })

		expect(post.mock.calls[0][0]).toContain('/flow-tasks/t1/complete')
		expect(post.mock.calls[0][1]).toEqual({ comment: 'done' })
		expect(task.state).toBe('completed')
	})

	it('keeps the engine refusal message, which names the verb and the reason', async () => {
		post.mockRejectedValue({
			response: {
				data: { message: "Verb 'complete' denied: not the assignee" },
			},
		})
		const store = useEngineTaskStore()

		const task = await store.invoke('t1', 'complete')

		expect(task).toBeNull()
		// The engine's own words. A generic "request failed" throws away the
		// only part of the refusal a handler can act on.
		expect(store.error).toBe("Verb 'complete' denied: not the assignee")
	})

	it('surfaces a failed list instead of reporting an empty one', async () => {
		get.mockRejectedValue(new Error('network is down'))
		const store = useEngineTaskStore()

		const rows = await store.list()

		expect(rows).toEqual([])
		// An empty list with no trace of why is indistinguishable from a
		// genuinely empty one.
		expect(store.error).toBe('network is down')
		expect(store.loading).toBe(false)
	})

	it('never calls the engine for a blank id', async () => {
		const store = useEngineTaskStore()

		expect(await store.fetch('')).toBeNull()
		expect(await store.openForCase('')).toEqual([])
		expect(await store.invoke('', 'complete')).toBeNull()
		expect(await store.invoke('t1', '')).toBeNull()

		expect(get).not.toHaveBeenCalled()
		expect(post).not.toHaveBeenCalled()
	})
})

describe('the read path speaks the register vocabulary', () => {
	/**
	 * 🔴 THE WRITE PATH MAPPED AND THE READ PATH DID NOT.
	 *
	 * `create()` has translated `dueDate` to the engine's `dueAt` since the
	 * first day. Rows came back raw, so every component asking for
	 * `row.dueDate` got `undefined` and rendered its empty state: a task due
	 * today showed "No due date" on the case pane. Nothing failed, because
	 * a task without a deadline is a legitimate thing, so the surface looked
	 * correct while telling a handler the opposite of the truth.
	 */
	it('gives a row the names the components read', () => {
		const row = asTaskRow({
			uuid: 'task-1',
			state: 'active',
			dueAt: '2026-09-10T00:00:00+00:00',
			objectUuid: 'case-9',
		})

		expect(row.id).toBe('task-1')
		expect(row.status).toBe('active')
		expect(row.dueDate).toBe('2026-09-10T00:00:00+00:00')
		expect(row.case).toBe('case-9')
	})

	it('keeps the engine names alongside, because isTerminal reads state', () => {
		const row = asTaskRow({
			uuid: 'task-1',
			state: 'completed',
			isTerminal: true,
		})

		expect(row.uuid).toBe('task-1')
		expect(row.state).toBe('completed')
		expect(isTerminal(row)).toBe(true)
	})

	it('does not invent a deadline for a task that has none', () => {
		const row = asTaskRow({ uuid: 'task-1', state: 'active' })

		// `not.toHaveProperty`, not `toBeUndefined`: the latter passes
		// whether the key is absent or present-and-undefined, so it could
		// not tell "no deadline" from "we wrote undefined onto every row".
		expect(row).not.toHaveProperty('dueDate')
		expect(row).not.toHaveProperty('case')
	})

	it('maps every row a list returns', async () => {
		get.mockResolvedValue({
			data: {
				results: [
					{
						uuid: 'task-1',
						state: 'active',
						dueAt: '2026-09-10T00:00:00+00:00',
					},
					{ uuid: 'task-2', state: 'available' },
				],
				total: 2,
			},
		})

		const rows = await useEngineTaskStore().list({ scope: 'all' })

		expect(rows[0].dueDate).toBe('2026-09-10T00:00:00+00:00')
		expect(rows[0].status).toBe('active')
		expect(rows[1].status).toBe('available')
	})

	it('maps the single task a fetch returns', async () => {
		get.mockResolvedValue({
			data: {
				uuid: 'task-1',
				state: 'active',
				dueAt: '2026-09-11T00:00:00+00:00',
				objectUuid: 'case-4',
			},
		})

		const task = await useEngineTaskStore().fetch('task-1')

		expect(task.dueDate).toBe('2026-09-11T00:00:00+00:00')
		expect(task.case).toBe('case-4')
	})
})
