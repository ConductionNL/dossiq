/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakGerelateerd "Objecten": the object's name and the description wrap
 * onto a second line, and the object's own status is a pill.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const column = (key) => simple.configAppend.widgets.find((widget) => widget.id === 'case-objects-card').content.columns.find((col) => col.key === key)

describe('the case objects table', () => {
	it.each(['objectTitle', 'description'])('wraps %s rather than cutting it', (key) => {
		expect(column(key).cellClass).toBe('cn-cell--wrap')
	})

	it('draws the object status as a pill', () => {
		expect(column('objectStatus').widget).toBe('badge')
		expect(column('objectStatus').colorMap).toEqual({ 'In beheer': 'success', Gepland: 'info' })
	})

	it('names the status column as drawn', () => {
		expect(read('l10n/nl.json').translations['Object status']).toBe('Objectstatus')
	})
})
