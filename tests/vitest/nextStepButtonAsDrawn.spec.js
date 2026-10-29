/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaak and every case board: the "Volgende stap" button in the what-now
 * card is drawn as a label alone, with no icon before it.
 *
 * @spec openspec/changes/next-step-button-as-drawn/specs/next-step-button-as-drawn/spec.md#requirement-the-next-step-button-is-a-label
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const layout = JSON.parse(fs.readFileSync(path.join(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))

/**
 * The case page's stage actions.
 *
 * @return {object} `primaryActionByStage` of the case page.
 */
function stageActions() {
	const pages = Object.values(layout.pages || {})
	return pages.find((p) => p && p.id === 'CaseDetail').config.primaryActionByStage
}

describe('the next step button', () => {
	it('opens the lifecycle dialog in intake, in progress and review (control)', () => {
		const stages = stageActions()
		for (const stage of ['intake', 'in-progress', 'review']) {
			expect(stages[stage].id).toBe('stage-next-step')
			expect(stages[stage].target).toBe('CaseLifecycleMenuDialog')
		}
	})

	it('has no icon in any stage', () => {
		const entries = Object.values(stageActions()).filter((a) => a && typeof a === 'object' && a.id === 'stage-next-step')
		expect(entries.length).toBeGreaterThan(0)
		for (const entry of entries) {
			expect(entry.icon).toBeUndefined()
		}
	})
})
