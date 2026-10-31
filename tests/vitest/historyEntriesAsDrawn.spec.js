/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakHistorie draws an entry's moment as "6 okt, 15:40" and an icon per
 * kind in the rail circle; every icon it names is in the app's registry.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8'))
const icons = fs.readFileSync(path.join(ROOT, 'src/icons.js'), 'utf8')
const content = simple.pages.find((page) => page.id === 'CaseDetail').configPatch.widgets['case-timeline-panel'].content

describe('history entries as drawn', () => {
	it('writes the short moment', () => {
		expect(content.momentFormat).toBe('short')
	})

	it('names an icon for every kind on the board, each registered', () => {
		expect(Object.keys(content.kindIcons).sort()).toEqual(['bericht', 'document', 'ontvangstbevestiging', 'statuswijziging', 'taak', 'toewijzing'])
		for (const name of Object.values(content.kindIcons)) {
			expect(icons).toContain(`import ${name} from 'vue-material-design-icons/${name}.vue'`)
		}
	})
})
