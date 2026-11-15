/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakAssistent r34: beside the AI oversight tab the case header draws no
 * stages bars, as the what-now card and the stage action stand aside there
 * (the page stood 87px low under the bars). Needs nextcloud-vue
 * fix/r34-header-widget-follows-tab; before it the key is ignored.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8')).pages.find((page) => page.id === 'CaseDetail')

describe('the stages bars beside AI oversight', () => {
	it('stand aside wherever the what-now card does', () => {
		expect(simple.configPatch.widgets['case-stages'].notTabs).toEqual(simple.config.nextStep.notTabs)
	})
})
