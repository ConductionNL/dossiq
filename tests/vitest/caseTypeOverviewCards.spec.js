/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type Overview carries the board's cards and the phase board opens
 * the case type's workflow (boards DqZaaktypeOverzicht, DqZaaktype).
 *
 * @spec openspec/changes/case-type-overview-cards/specs/case-type-overview-cards/spec.md#requirement-the-case-type-overview-shows-the-boards-cards
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
		expect(overview.widgetIds).toEqual(['case-type-statuses', 'case-type-rules-summary', 'case-type-rights', 'case-type-privacy', 'case-type-translations'])
		expect(overview.layout.map((entry) => [entry.widgetId, entry.gridWidth])).toEqual([
			['case-type-statuses', 12], ['case-type-rules-summary', 12], ['case-type-rights', 12], ['case-type-privacy', 6], ['case-type-translations', 6],
		])
		overview.widgetIds.forEach((id) => expect(widget(id)).toBeTruthy())
	})

	it('lists the case schema rules with an On or Off pill', () => {
		const rules = widget('case-type-rules-summary').content
		expect(rules.endpointSource).toEqual({ url: '/apps/openregister/api/schemas/case/rules', responsePath: 'rules' })
		expect(rules.pill.field).toBe('enabled')
	})

	/**
	 * @spec openspec/changes/case-type-add-rule/specs/case-type-add-rule/spec.md#requirement-the-rules-card-adds-a-rule-as-a-new-flow
	 */
	it('adds a rule by opening a new flow', () => {
		expect(widget('case-type-rules-summary').headerLink).toEqual({ label: 'Add rule', route: 'FlowDetail', params: { id: 'new' } })
	})
})

/**
 * @spec openspec/changes/case-type-overview-cards/specs/case-type-overview-cards/spec.md#requirement-the-phase-board-opens-the-case-types-workflow
 */
describe('phase board', () => {
	it('links View as flow to the case type\'s workflow', () => {
		expect(widget('case-type-phase-board').headerLink).toEqual({ label: 'View as flow', route: 'WorkflowDefinitionDetail', params: { id: '@object.workflowDefinition' } })
	})
})
