/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The enforcement advice page as board DqHandhaving draws it: no Follow
 * control, the recommendation and the deviation as side cards, and the
 * history card titled as on every other record page.
 *
 * @spec openspec/specs/enforcement-advice-page/spec.md#requirement-the-enforcement-advice-page-follows-board-dqhandhaving
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Read a JSON file from the repository.
 *
 * @param {string} file Path under the repository root.
 * @return {object} The parsed document.
 */
function readJson(file) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
}

const page = readJson('src/manifest.json').pages.find((entry) => entry.id === 'LhsRecommendationDetail')
const nl = readJson('l10n/nl.json').translations

describe('enforcement advice page (DqHandhaving)', () => {
	it('draws no Follow control', () => {
		expect(page.config.follow).toBe(false)
	})

	it('shows the recommendation and the deviation as side cards, in board order', () => {
		const ids = page.config.sideColumn.map((card) => card.id)
		expect(ids).toEqual(['side-case', 'side-classification', 'side-recommendation', 'side-override', 'side-history'])
	})

	it('keeps every field the two former data panels showed', () => {
		const paths = (id) => page.config.sideColumn.find((card) => card.id === id).content.fields.map((field) => field.path).filter(Boolean)
		expect(paths('side-recommendation')).toEqual(expect.arrayContaining(['case', 'inspection', 'severity', 'behaviour', 'actorType', 'matrixVersion', 'recommendedIntervention', 'recommendedBy']))
		expect(paths('side-override')).toEqual(expect.arrayContaining(['finalIntervention', 'override', 'overrideJustification', 'overrideBy', 'overrideAuthority']))
	})

	it('stacks each override label above its value in the narrow side card', () => {
		expect(page.config.sideColumn.find((card) => card.id === 'side-override').content.columns).toBe(1)
	})

	it('names the advice in the header pill, not the intervention applied after a deviation', () => {
		expect(page.config.typePill.field).toBe('recommendedIntervention')
	})

	it('leaves only the matrix and the violation in the main column', () => {
		expect(page.config.widgets.map((widget) => widget.id).sort()).toEqual(['lhs-matrix', 'lhs-violation'])
		expect(page.config.layout.map((cell) => cell.widgetId).sort()).toEqual(['lhs-matrix', 'lhs-violation'])
	})

	it('titles the history card Historie, as the board does', () => {
		const history = page.config.sideColumn.find((card) => card.id === 'side-history')
		expect(nl[history.title]).toBe('Historie')
	})

	it('has a Dutch string for every label and line on the side cards', () => {
		// A one-character map value is a matrix code (2, C), the same in every language.
		const words = (map) => Object.values(map || {}).filter((value) => value.length > 1)
		for (const card of page.config.sideColumn) {
			expect(nl[card.title], card.title).toBeTruthy()
			for (const field of card.content?.fields || []) {
				for (const text of [field.label, field.text, ...words(field.map)].filter(Boolean)) {
					expect(nl[text], text).toBeTruthy()
				}
			}
		}
	})
})

describe('the Wat nu? button on the enforcement advice (DqHandhaving)', () => {
	const stages = page.config.nextStep.stages

	it('follows the intervention the advice settles on', () => {
		expect(page.config.stageField).toBe(page.config.nextStep.field)
	})

	it('drafts the pre-announcement or the warning, for every stage the card knows', () => {
		for (const stage of Object.keys(stages)) {
			const action = page.config.primaryActionByStage[stage]
			expect(action, stage).toMatchObject({ type: 'open-modal', target: 'BeschikkingComposerDialog', props: { caseId: '@object.case' } })
			const preAnnouncement = stages[stage].title === 'What now? Pre-announcement'
			expect(action.label, stage).toBe(preAnnouncement ? 'Draft the pre-announcement' : 'Draft the warning')
		}
		expect(nl['Draft the pre-announcement']).toBe('Vooraankondiging opstellen')
	})

	it('draws the button as text only, as the board does', () => {
		for (const stage of Object.keys(page.config.primaryActionByStage)) {
			expect(page.config.primaryActionByStage[stage].icon, stage).toBeUndefined()
		}
	})
})

describe('the Historie card on the enforcement advice (DqHandhaving)', () => {
	const history = page.config.sideColumn.find((card) => card.id === 'side-history')

	it('lists what happened, not who opened the advice', () => {
		expect(history.content.excludeActions).toEqual(['read'])
	})

	it('puts who and when under the action, in words', () => {
		expect(history.content.layout).toBe('stacked')
		expect(nl[history.content.actionLabels.create]).toBe('Advies berekend')
	})
})
