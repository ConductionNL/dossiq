/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The enforcement advice page as board DqHandhaving draws it: no Follow
 * control, the recommendation and the deviation as side cards, and the
 * history card titled as on every other record page.
 *
 * @spec openspec/changes/r21-enforcement-advice-board/specs/enforcement-advice-page/spec.md#requirement-the-enforcement-advice-page-follows-board-dqhandhaving
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

	it('leaves only the matrix and the violation in the main column', () => {
		expect(page.config.widgets.map((widget) => widget.id).sort()).toEqual(['lhs-matrix', 'lhs-violation'])
		expect(page.config.layout.map((cell) => cell.widgetId).sort()).toEqual(['lhs-matrix', 'lhs-violation'])
	})

	it('titles the history card Historie, as the board does', () => {
		const history = page.config.sideColumn.find((card) => card.id === 'side-history')
		expect(nl[history.title]).toBe('Historie')
	})

	it('has a Dutch string for every label and line on the side cards', () => {
		for (const card of page.config.sideColumn) {
			expect(nl[card.title], card.title).toBeTruthy()
			for (const field of card.content?.fields || []) {
				for (const text of [field.label, field.text, ...Object.values(field.map || {})].filter(Boolean)) {
					expect(nl[text], text).toBeTruthy()
				}
			}
		}
	})
})
