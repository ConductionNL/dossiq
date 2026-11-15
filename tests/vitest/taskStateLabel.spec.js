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
import { priorityPillTone } from '../../src/utils/priorityValues.js'
import { taskStateLabel, taskStateTone } from '../../src/utils/taskStateLabel.js'

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

	it('draws an active task purple, a finished one green and the rest neutral', () => {
		expect(
			[
				'available',
				'enabled',
				'active',
				'completed',
				'terminated',
				'disabled',
				'suspended',
				null,
			].map(taskStateTone),
		).toEqual([
			'default',
			'default',
			'purple',
			'success',
			'default',
			'default',
			'default',
			'default',
		])
	})

	it('shows an unknown state as written and no state as nothing', () => {
		expect(taskStateLabel('suspended')).toBe('suspended')
		expect(taskStateLabel(null)).toBe('')
		expect(taskStateLabel(' ')).toBe('')
	})

	it('is the one wording the task page uses', () => {
		// The task page is a manifest detail page: its state pill maps each
		// engine state to the same words, translated through the same catalogue.
		const manifest = JSON.parse(readFileSync('src/manifest.json', 'utf8'))
		const pill = manifest.pages.find((entry) => entry.id === 'TaskDetail').config.typePill
		expect(pill.field).toBe('state')
		for (const state of ['available', 'enabled', 'active', 'completed', 'terminated', 'disabled']) {
			expect(pill.labels[state], state).toBe(taskStateLabel(state))
		}
		expect(pill.colorMap.active).toBe(taskStateTone('active'))
		expect(pill.colorMap.completed).toBe(taskStateTone('completed'))
	})
})

describe('priorityPillTone', () => {
	it('gives a pill only to a priority that asks for attention', () => {
		expect(
			['low', 'normal', 'high', 'urgent', 'other', null].map(priorityPillTone),
		).toEqual(['', '', 'warning', 'error', '', ''])
	})
})
