/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTaak's Zaak card names the case in bold and links to it once, with
 * "Naar de zaak" under the facts; the title is not a second link to the same
 * page.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const task = manifest.pages.find((page) => page.id === 'TaskDetail').config
const card = task.sideColumn.find((entry) => typeof entry === 'object' && entry.id === 'side-case')

describe('the task\'s Zaak card (DqTaak)', () => {
	it('names the case without making the name a link', () => {
		const title = card.content.fields.find((field) => field.key === 'case-title')
		expect(title.as).toBe('title')
		expect(title.route).toBeUndefined()
	})

	it('links to the case once, under the facts', () => {
		expect(card.content.link.route).toMatchObject({ name: 'CaseDetail' })
	})
})
