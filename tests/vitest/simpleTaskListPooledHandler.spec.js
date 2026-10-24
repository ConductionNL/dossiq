/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqTaken names an unclaimed task's team in the Behandelaar column:
 * "TW  Team Woo, niet geclaimd".
 *
 * @spec openspec/changes/task-list-pooled-handler/specs/task-list/spec.md#requirement-the-task-list-names-the-team-of-an-unclaimed-task
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const tasks = read('src/menu-layout.simple.json').pages.find((entry) => entry.id === 'Tasks').config
const nl = read('l10n/nl.json').translations

describe('simple profile task list (DqTaken) handler column', () => {
	const handler = tasks.columns.find((entry) => entry.key === 'assignee')

	it('names the candidate team of a task nobody holds', () => {
		expect(handler.widgetProps).toMatchObject({ user: true, groupField: 'candidateGroups' })
		expect(nl[handler.widgetProps.groupLabel]).toBe('{group}, niet geclaimd')
	})
})
