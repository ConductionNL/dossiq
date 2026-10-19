/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaaktypeOverzicht, Controle: the case type's Overview tab shows the
 * warnings publishing would give, read from the publish check, as one warning
 * notice. The board draws it on Overview alone, so it is no side card.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { describe, expect, it } from 'vitest'
import layout from '../../src/menu-layout.simple.json'

const page = layout.pages.find((p) => p.id === 'CaseTypeDetail')
const card = page.configAppend.widgets.find((w) => w.id === 'case-type-check')
const tabs = page.configAppend.widgets.find((w) => w.id === 'case-type-panels').content.tabs
const tabWidgets = (tab) => [tab.widgetId, ...(tab.widgetIds || []), ...(tab.layout || []).map((l) => l.widgetId)].filter(Boolean)

describe('the check card of a case type', () => {
	it('reads the publish warnings as a warning notice', () => {
		expect(card.content.endpointSource).toEqual({ url: '/apps/dossiq/api/case-types/@objectId/publish/validate', responsePath: 'warnings' })
		expect(card.content.presentation).toBe('notice')
		expect(card.content.variant).toBe('warning')
	})

	it('shows on the Overview tab only, not in the side column', () => {
		const sideIds = page.config.sideColumn.map((entry) => (typeof entry === 'string' ? entry : entry.id))
		expect(sideIds).not.toContain('side-case-type-check')
		expect(sideIds).not.toContain('case-type-check')
		const holding = tabs.filter((tab) => tabWidgets(tab).includes('case-type-check')).map((tab) => tab.label)
		expect(holding).toEqual(['Overview'])
	})

	it('stays one compact box: one warning drawn, the count in a plural-free lead', () => {
		expect(card.content.limit).toBe(1)
		expect(card.content.lead).toBe('Warnings ({total}):')
	})
})
