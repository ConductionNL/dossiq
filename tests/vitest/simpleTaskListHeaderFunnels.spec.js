/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqTaken: Onderwerp, Status, Prioriteit and Deadline filter from their
 * headers through the inbox's search fields; Taak and Behandelaar do not.
 *
 * @spec openspec/changes/task-list-header-funnels/specs/task-list/spec.md#requirement-the-task-list-filters-from-its-column-headers
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const tasks = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))
	.pages.find((entry) => entry.id === 'Tasks').config
const searchFields = Object.keys(JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
	.pages.find((entry) => entry.id === 'Tasks').config.sidebar.fields)

describe('simple profile task list (DqTaken) header funnels', () => {
	it('filters Subject, Status, Priority and Deadline through the search fields', () => {
		const filtering = tasks.columns
			.filter((column) => searchFields.includes(column.filterField || column.key))
			.map((column) => [column.key, column.filterField || column.key])
		expect(filtering).toEqual([['subjectLabel', 'objectUuid'], ['state', 'state'], ['priorityLabel', 'priority'], ['dueAt', 'dueAt']])
	})
})
