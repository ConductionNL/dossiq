/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every case board names the files tab "Bestanden 7": the word the boards use,
 * and the number of files in the case's folder.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const nl = read('l10n/nl.json').translations
const tab = simple.configPatch.widgets['case-panels'].content.tabs.find((entry) => entry.widgetId === 'case-files' || (entry.widgetIds || []).includes('case-files'))

describe('the case files tab', () => {
	it('is called Bestanden, as the boards call it', () => {
		expect(nl[tab.label]).toBe('Bestanden')
	})

	it('counts the files in the case folder', () => {
		expect(tab.countFrom).toEqual({ endpoint: '/apps/openregister/api/objects/dossiq/case/@objectId/files', field: 'total' })
	})
})
