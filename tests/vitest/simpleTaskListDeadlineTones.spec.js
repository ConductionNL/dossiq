/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqTaken's deadlines: "2 dagen te laat" and "Vandaag" in red with a red
 * edge on the row, "Morgen" and "Vrijdag 9 okt" in orange, a finished task's
 * deadline as "Klaar op 6 okt" in grey.
 *
 * @spec openspec/changes/task-list-deadline-tones/specs/task-list/spec.md#requirement-the-task-list-draws-a-deadline-in-its-urgency
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const tasks = read('src/menu-layout.simple.json').pages.find((entry) => entry.id === 'Tasks').config
const nl = read('l10n/nl.json').translations

describe('simple profile task list (DqTaken) deadlines', () => {
	const due = tasks.columns.find((entry) => entry.key === 'dueAt').widgetProps.due

	it('draws today red, the next two days orange, the coming week by name', () => {
		expect(due).toMatchObject({ todayTone: 'danger', soonWithin: 2, nearWithin: 6 })
	})

	it('reads a finished task\'s deadline as "Klaar op ..."', () => {
		expect(due.activeWhen).toEqual({ field: 'isTerminal', equals: false })
		expect(nl[due.inactiveLabel]).toBe('Klaar op {date}')
	})

	it('marks an open task due today or earlier with the overdue edge', () => {
		expect(tasks.rowClass).toEqual([{
			when: [{ field: 'dueAt', op: 'lt', value: '@today+1d' }, { field: 'isTerminal', op: 'eq', value: false }],
			class: 'cn-row--overdue',
		}])
	})
})
