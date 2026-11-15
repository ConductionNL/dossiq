/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqTaken draws a task's title and subject over as many lines as they
 * need ("Bezwaarschrift aanvullen bij de indiener"), not cut to "Bezwaarschrift a...".
 *
 * @spec openspec/changes/task-list-titles-wrap/specs/task-list/spec.md#requirement-the-task-list-shows-a-title-and-subject-in-full
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const tasks = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))
	.pages.find((entry) => entry.id === 'Tasks').config

describe('simple profile task list (DqTaken) wraps its titles', () => {
	it.each(['title', 'subjectLabel'])('lets the %s column wrap', (key) => {
		const column = tasks.columns.find((entry) => entry.key === key)
		expect(column.cellClass.split(' ')).toContain('cn-cell--wrap')
	})

	it('keeps the title strong', () => {
		const column = tasks.columns.find((entry) => entry.key === 'title')
		expect(column.cellClass.split(' ')).toContain('cn-cell--strong')
	})
})
