/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards DqTaken and DqContacten draw people as initials chips: 28px on the
 * task list, 36px grey on the contacts list.
 *
 * @spec openspec/changes/person-chips/specs/party-pages/spec.md#requirement-people-in-lists-are-initials-chips-as-the-boards-draw-them
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))

describe('person chips', () => {
	it('DqTaken: a 28px chip for the handler', () => {
		const tasks = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'Tasks').config
		expect(tasks.columns.find((column) => column.key === 'assignee').widgetProps.size).toBe(28)
	})

	it('DqContacten: a 36px grey chip for the person', () => {
		const contacts = read('src/manifest.json').pages.find((page) => page.id === 'Contacts').config
		expect(contacts.columns.find((column) => column.key === 'displayName').widgetProps).toEqual({ size: 36, tone: 'neutral' })
	})
})
