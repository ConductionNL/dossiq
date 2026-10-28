/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Historie side cards of DqSociaalDomein and DqHandhaving draw compact
 * stacked rows: 10px above and below each row, the first 10px under the title.
 *
 * @spec openspec/changes/history-cards-compact-rows/specs/case-page/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const caseDetail = readJson('src', 'menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail').config
const advice = readJson('src', 'manifest.json').pages.find((page) => page.id === 'LhsRecommendationDetail').config
const sideCard = (config, id) => config.sideColumn.find((entry) => typeof entry === 'object' && entry.id === id)

describe('the Historie side cards with compact rows', () => {
	it('draws the social case history compact, as DqSociaalDomein does', () => {
		const history = sideCard(caseDetail, 'side-social-history')
		expect(history.type).toBe('audit-trail')
		expect(history.content.layout).toBe('stacked')
		expect(history.content.density).toBe('compact')
	})

	it('draws the enforcement advice history compact, as DqHandhaving does', () => {
		const history = sideCard(advice, 'side-history')
		expect(history.type).toBe('audit-trail')
		expect(history.content.layout).toBe('stacked')
		expect(history.content.density).toBe('compact')
	})
})
