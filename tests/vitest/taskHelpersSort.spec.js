// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The task sort: status group first, then priority, then due date with the
 * tasks without one last.
 *
 * @spec openspec/specs/task-management/spec.md
 */
import { describe, expect, it } from 'vitest'
import { sortTasks } from '../../src/utils/taskHelpers.js'

const ids = (tasks) => tasks.map((task) => task.id)

describe('sortTasks', () => {
	it('puts the status groups in order, unknown statuses last', () => {
		const tasks = [
			{ id: 'unknown', status: 'toString' },
			{ id: 'disabled', status: 'disabled' },
			{ id: 'terminated', status: 'terminated' },
			{ id: 'completed', status: 'completed' },
			{ id: 'available', status: 'available' },
			{ id: 'active', status: 'active' },
			{ id: 'none' },
		]
		expect(ids(sortTasks(tasks))).toEqual([
			'active', 'available', 'completed', 'terminated', 'disabled', 'unknown', 'none',
		])
	})

	it('sorts by priority inside a group, an unknown priority as normal', () => {
		const tasks = [
			{ id: 'low', status: 'active', priority: 'low' },
			{ id: 'odd', status: 'active', priority: 'whenever', dueDate: '2026-10-01' },
			{ id: 'normal', status: 'active', priority: 'normal', dueDate: '2026-10-02' },
			{ id: 'urgent', status: 'active', priority: 'urgent' },
			{ id: 'high', status: 'active', priority: 'high' },
		]
		expect(ids(sortTasks(tasks))).toEqual(['urgent', 'high', 'odd', 'normal', 'low'])
	})

	it('sorts by due date last, the tasks without one after the others', () => {
		const tasks = [
			{ id: 'none-a', status: 'active' },
			{ id: 'late', status: 'active', dueDate: '2026-10-09' },
			{ id: 'none-b', status: 'active' },
			{ id: 'early', status: 'active', dueDate: '2026-10-01' },
		]
		expect(ids(sortTasks(tasks))).toEqual(['early', 'late', 'none-a', 'none-b'])
	})

	it('returns a sorted copy and leaves the input alone', () => {
		const tasks = [{ id: 'b', status: 'completed' }, { id: 'a', status: 'active' }]
		const sorted = sortTasks(tasks)
		expect(sorted).not.toBe(tasks)
		expect(ids(tasks)).toEqual(['b', 'a'])
	})
})
