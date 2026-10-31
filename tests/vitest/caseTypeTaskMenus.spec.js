/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktype's Fasen en taken card ends every task in a "···" menu. A task
 * is edited on the case type's flow page, so the menu offers that.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const page = read('src/menu-layout.simple.json').pages.find((entry) => entry.id === 'CaseTypeDetail')
const board = page.configAppend.widgets.find((widget) => widget.id === 'case-type-phase-board').content
const nl = read('l10n/nl.json').translations

describe('the per-task menus (DqZaaktype)', () => {
	it('puts each task\'s actions in one menu', () => {
		expect(board.itemActionsMenu).toBe(true)
	})

	it('edits a task on the case type\'s flow page', () => {
		const [edit] = board.itemActions
		expect(edit).toEqual(expect.objectContaining({ type: 'navigate', target: '/settings/case-types/@objectId/flow' }))
		expect(nl[edit.label]).toBe('Bewerken in de flow')
	})
})
