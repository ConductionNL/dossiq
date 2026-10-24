/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqTaken opens sorted on Deadline, ascending ("gesorteerd op deadline"),
 * and its Priority and Deadline headers sort the inbox.
 *
 * @spec openspec/changes/task-list-sorts-by-deadline/specs/task-list/spec.md#requirement-the-task-list-sorts-by-deadline-and-priority
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const tasks = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))
	.pages.find((entry) => entry.id === 'Tasks').config

describe('simple profile task list (DqTaken) sorting', () => {
	it('opens on the deadline, soonest first', () => {
		expect(tasks.sortKey).toBe('dueAt')
		expect(tasks.sortOrder).toBe('asc')
	})

	it('lets the Priority and Deadline headers sort', () => {
		const sortable = tasks.columns.filter((column) => column.sortable === true).map((column) => column.key)
		expect(sortable).toEqual(['priorityLabel', 'dueAt'])
	})
})
