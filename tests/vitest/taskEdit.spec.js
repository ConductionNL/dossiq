/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTaak "Bewerken": the library's tasks source offers Edit on an open task
 * and saves it through the engine's PATCH, which decides who may. dossiq
 * leaves that verb to the engine (no app route stands in for it, no header
 * action shadows it) and words the history line the edit leaves.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const page = read('src/manifest.json').pages.find((entry) => entry.id === 'TaskDetail')
const nl = read('l10n/nl.json').translations

describe('editing a task', () => {
	it('goes to the engine: no app route for edit and no header action of the same id', () => {
		expect(page.config.entitySource).toBe('tasks')
		expect(page.config.verbEndpoints?.edit).toBeUndefined()
		expect((page.config.headerActions || []).map((action) => action.id)).not.toContain('edit')
	})

	it('words the history line an edit leaves', () => {
		const label = page.config.widgets.find((widget) => widget.id === 'task-history').content.actionLabels.edit
		expect(nl[label]).toBe('Taak bewerkt door {actor}')
	})
})
