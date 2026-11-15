/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqTaken draws a task's subject as a link to its case with the case
 * number under it ("Bezwaar parkeerboete Havenstraat" / "2026-0074").
 *
 * @spec openspec/changes/task-list-subject-links-its-case/specs/task-list/spec.md#requirement-the-task-list-links-a-tasks-case-with-its-number
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const tasks = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))
	.pages.find((entry) => entry.id === 'Tasks').config
const subject = tasks.columns.find((entry) => entry.key === 'subjectLabel')

describe('simple profile task list (DqTaken) subject column', () => {
	it('links a dossiq task\'s subject to its case page', () => {
		expect(subject.widget).toBe('link')
		expect(subject.widgetProps.routeField).toBe('appId')
		expect(subject.widgetProps.routeMap).toEqual({ dossiq: 'CaseDetail' })
		expect(subject.widgetProps.params).toEqual({ id: 'objectUuid' })
	})

	it('shows the case number under the subject', () => {
		expect(subject.secondary).toBe('{subject.identifier}')
	})
})
