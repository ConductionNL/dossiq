/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaaktype Mijlpalen: the Phases tab lists the case type's milestones
 * in order and can add one.
 *
 * @spec openspec/specs/milestone-tracking/spec.md
 */

import { describe, expect, it } from 'vitest'
import layout from '../../src/menu-layout.simple.json'

const page = layout.pages.find((p) => p.id === 'CaseTypeDetail')
const widget = (id) => page.configAppend.widgets.find((w) => w.id === id)

describe('the milestones card', () => {
	it('closes the Phases tab', () => {
		const phases = widget('case-type-panels').content.tabs.find((t) => t.id === 'phases')
		expect(phases.widgetIds.at(-1)).toBe('case-type-milestones')
	})

	it('reads the case type\'s own milestones in order', () => {
		expect(widget('case-type-milestones').content).toMatchObject({ schema: 'milestoneDefinition', filter: { caseType: '@objectId' }, sort: { field: 'order', dir: 'asc' } })
	})

	it('adds a milestone to this case type', () => {
		expect(widget('case-type-milestones').headerActions[0].action).toMatchObject({ type: 'open-form', schema: 'milestoneDefinition', props: { caseType: '@objectId' } })
	})
})
