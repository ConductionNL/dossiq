/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every case board draws the history tab as "Historie 10": the number of
 * entries on the case's timeline, which the tab lists.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const tab = simple.configPatch.widgets['case-panels'].content.tabs.find((entry) => entry.label === 'Case history')

describe('the case history tab', () => {
	it('counts the entries on the case\'s timeline', () => {
		expect(tab.countFrom).toEqual({ endpoint: '/apps/openregister/api/objects/dossiq/case/@objectId/timeline', field: 'total' })
	})
})
