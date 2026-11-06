/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqContact and DqOrganisatie "Historie": "Laatst gewijzigd op 3 oktober door
 * Pieter Jansen". The page reads who changed the record last
 * (`recordLastChange`) and the side card names them.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const nl = read('l10n/nl.json').translations
const full = read('src/manifest.json')

describe('the history side card names who changed the record last', () => {
	it.each(['ContactDetail', 'OrganisationDetail'])('%s', (id) => {
		const page = full.pages.find((entry) => entry.id === id)
		expect(page.config.recordLastChange).toBe(true)
		const card = page.config.sideColumn.find((widget) => widget.id === 'side-history')
		const parts = card.content.fields[0].parts
		const last = parts[parts.length - 1]
		expect(last).toEqual({ path: '_lastChange.userName', label: 'last changed by' })
		expect(nl[last.label]).toBe('door')
	})
})
