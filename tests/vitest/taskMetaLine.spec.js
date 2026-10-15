// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The line after the crumbs on the task page (DqTaak): "bij zaak 2026-0074,
 * deadline vandaag".
 *
 * @spec openspec/specs/task-management/spec.md
 */
import { describe, expect, it } from 'vitest'
import { taskDeadlineWords, taskMetaLine } from '../../src/utils/taskMetaLine.js'

const NOW = new Date(2026, 9, 7, 15, 30)

describe('taskDeadlineWords', () => {
	it.each([
		['2026-10-07', 'deadline today'],
		['2026-10-07T23:00:00+00:00', 'deadline today'],
		['2026-10-08', 'deadline tomorrow'],
		['2026-10-06', 'deadline yesterday'],
		['2026-10-14', 'deadline in 7 days'],
		['2026-10-01', 'deadline 6 days ago'],
	])('words %s as "%s"', (dueAt, words) => {
		expect(taskDeadlineWords(dueAt, NOW)).toBe(words)
	})

	it('says nothing for a task with no readable due date', () => {
		expect(taskDeadlineWords(null, NOW)).toBe('')
		expect(taskDeadlineWords('', NOW)).toBe('')
		expect(taskDeadlineWords('not a date', NOW)).toBe('')
	})
})

describe('taskMetaLine', () => {
	it('joins the case number and the deadline', () => {
		expect(
			taskMetaLine({ caseNumber: '2026-0074', dueAt: '2026-10-07', now: NOW }),
		).toBe('on case 2026-0074, deadline today')
	})

	it('draws the part that is known', () => {
		expect(taskMetaLine({ dueAt: '2026-10-08', now: NOW })).toBe(
			'deadline tomorrow',
		)
		expect(taskMetaLine({ caseNumber: ' 2026-0074 ', now: NOW })).toBe(
			'on case 2026-0074',
		)
		expect(taskMetaLine({ now: NOW })).toBe('')
	})

	it('leaves the deadline out of a finished task', () => {
		expect(
			taskMetaLine({
				caseNumber: '2026-0074',
				dueAt: '2026-08-15',
				finished: true,
				now: NOW,
			}),
		).toBe('on case 2026-0074')
	})
})
