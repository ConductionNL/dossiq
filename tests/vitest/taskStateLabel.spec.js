// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * One wording for the engine's task states, used by the case plan and the
 * task page alike.
 *
 * @spec openspec/specs/task-management/spec.md
 */
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { taskStateLabel } from '../../src/utils/taskStateLabel.js'

describe('taskStateLabel', () => {
	it('words every state the engine persists', () => {
		// openregister lib/Db/Task.php Task::STATES.
		expect(
			[
				'available',
				'enabled',
				'active',
				'completed',
				'terminated',
				'disabled',
			].map(taskStateLabel),
		).toEqual([
			'Waiting',
			'Ready to start',
			'In execution',
			'Done',
			'Stopped',
			'Skipped',
		])
	})

	it('shows an unknown state as written and no state as nothing', () => {
		expect(taskStateLabel('suspended')).toBe('suspended')
		expect(taskStateLabel(null)).toBe('')
		expect(taskStateLabel(' ')).toBe('')
	})

	it('is the one wording: the case plan and the task page both use it', () => {
		for (const file of [
			'src/components/case/CasePlanPanel.vue',
			'src/views/tasks/TaskDetailView.vue',
		]) {
			expect(readFileSync(file, 'utf8'), file).toContain('taskStateLabel(')
		}
	})
})
