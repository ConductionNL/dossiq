/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakArchivering: the Archiving tab holds the archiving card and, under
 * it, Bewaring (who has held the case); there is no Custody tab of its own.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8')).pages.find((page) => page.id === 'CaseDetail')
const tabs = simple.configPatch.widgets['case-panels'].content.tabs
const ids = (tab) => tab.widgetIds || [tab.widgetId]

describe('the Archiving tab', () => {
	it('stacks the archiving card and Bewaring', () => {
		const archiving = tabs.find((tab) => tab.label === 'Archiving')
		expect(ids(archiving)).toEqual(['case-archival-panel', 'case-custody-panel'])
	})

	it('leaves no Custody tab of its own', () => {
		expect(tabs.filter((tab) => ids(tab).includes('case-custody-panel'))).toHaveLength(1)
		expect(tabs.find((tab) => tab.label === 'Custody')).toBeUndefined()
	})

	it('draws Bewaring as a titled card in the stack, as the board', () => {
		const registry = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
		const entry = registry.slice(registry.indexOf("'case-custody-pane': {"), registry.indexOf('DocumentCompareDialog: {'))
		expect(entry).toMatch(/\n\t\tchrome: 'card',/)
	})
})
