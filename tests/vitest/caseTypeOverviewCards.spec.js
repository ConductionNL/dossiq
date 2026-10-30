/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type Overview carries the board's cards and the phase board opens
 * the case type's workflow (boards DqZaaktypeOverzicht, DqZaaktype).
 *
 * @spec openspec/specs/case-type-overview-cards/spec.md#requirement-the-case-type-overview-shows-the-boards-cards
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const read = (file) => JSON.parse(fs.readFileSync(path.resolve(__dirname, '../..', file), 'utf8'))
const page = read('src/menu-layout.simple.json').pages.find((entry) => entry.id === 'CaseTypeDetail')
const widgets = [...page.configAppend.widgets, ...read('src/manifest.json').pages.find((entry) => entry.id === 'CaseTypeDetail').config.widgets]
const widget = (id) => widgets.find((entry) => entry.id === id)
const overview = widget('case-type-panels').content.tabs.find((tab) => tab.id === 'overview')

describe('case type Overview cards', () => {
	it('shows the board\'s cards in its order', () => {
		expect(overview.widgetIds).toEqual(['case-type-statuses', 'case-type-rules-summary', 'case-type-rights-summary', 'case-type-privacy-summary', 'case-type-translations-summary'])
		expect(overview.layout.map((entry) => [entry.widgetId, entry.gridWidth])).toEqual([
			['case-type-statuses', 12], ['case-type-rules-summary', 12], ['case-type-rights-summary', 12], ['case-type-privacy-summary', 6], ['case-type-translations-summary', 6],
		])
		overview.widgetIds.forEach((id) => expect(widget(id)).toBeTruthy())
	})

	it('lists the case type\'s own rules, as the Rules tab does (case-type-rules-summary-sentences)', () => {
		expect(widget('case-type-rules-summary').type).toBe('CaseTypeRulesWidget')
		expect(widget('case-type-rules-summary').content).toEqual({ mode: 'summary' })
	})
})

/**
 * @spec openspec/specs/case-type-overview-cards/spec.md#requirement-the-phase-board-opens-the-case-types-workflow
 */
describe('phase board', () => {
	it('links View as flow to the case type\'s workflow canvas', () => {
		// The canvas sits on a page of its own (case-type-page-follows-its-boards).
		expect(widget('case-type-phase-board').content.actions[0]).toEqual(expect.objectContaining({ type: 'navigate', label: 'View as flow', target: '/settings/case-types/@objectId/flow' }))
	})
})
