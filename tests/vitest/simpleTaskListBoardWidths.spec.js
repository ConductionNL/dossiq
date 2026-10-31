/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqTaken draws the task list's columns at fixed widths (Taak 150,
 * Onderwerp 168, Status 185, Prioriteit 133, Deadline 133, the handler takes
 * the rest), so the headings stand where the board puts them.
 *
 * @spec openspec/specs/task-list/spec.md#requirement-the-task-list-columns-take-the-boards-widths
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const layout = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8'))

/**
 * The column lists in the layout that hold the task list's subject column.
 *
 * @param {unknown} node The tree.
 * @param {Array<Array<object>>} out The lists found.
 * @return {Array<Array<object>>} The lists.
 */
function taskColumnLists(node, out = []) {
	if (Array.isArray(node)) {
		if (node.some((col) => col && col.key === 'subjectLabel') && node.some((col) => col && col.key === 'priorityLabel')) {
			out.push(node)
		}
		node.forEach((child) => taskColumnLists(child, out))
	} else if (node && typeof node === 'object') {
		Object.values(node).forEach((child) => taskColumnLists(child, out))
	}
	return out
}

describe('task list board widths', () => {
	it('gives each column the board\'s width', () => {
		const [columns] = taskColumnLists(layout)
		const width = (key) => columns.find((col) => col.key === key).width
		expect([width('title'), width('subjectLabel'), width('state'), width('priorityLabel'), width('dueAt')]).toEqual(['150px', '168px', '185px', '133px', '133px'])
	})
})
