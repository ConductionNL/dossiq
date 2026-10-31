/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakAssistent: beside the AI oversight tab the header has no "Volgende
 * stap"; the what-now card stands aside there and so does the stage action
 * (it stays under Meer). Needs nextcloud-vue feat/stage-primary-follows-tab.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8')).pages.find((page) => page.id === 'CaseDetail')

describe('the stage action beside AI oversight', () => {
	it('stands aside wherever the what-now card does', () => {
		const stages = simple.config.primaryActionByStage
		for (const stage of ['intake', 'in-progress', 'review']) {
			expect(stages[stage].notTabs, stage).toEqual(simple.config.nextStep.notTabs)
		}
		expect(simple.config.nextStep.notTabs).toEqual(['ai-oversight'])
	})
})
