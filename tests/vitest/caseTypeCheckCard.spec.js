/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaaktypeOverzicht, Controle: the case type's Overview tab shows the
 * warnings publishing would give, read from the publish check, as one warning
 * notice. The board draws it in the side column beside Overview alone.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { describe, expect, it } from 'vitest'
import layout from '../../src/menu-layout.simple.json'

const page = layout.pages.find((p) => p.id === 'CaseTypeDetail')
const card = page.config.sideColumn.find((w) => w.id === 'side-case-type-check')
const tabs = page.configAppend.widgets.find((w) => w.id === 'case-type-panels').content.tabs
const tabWidgets = (tab) => [tab.widgetId, ...(tab.widgetIds || []), ...(tab.layout || []).map((l) => l.widgetId)].filter(Boolean)

describe('the check card of a case type', () => {
	it('reads the publish warnings as a warning notice', () => {
		expect(card.content.endpointSource).toEqual({ url: '/apps/dossiq/api/case-types/@objectId/publish/validate', responsePath: 'warnings' })
		expect(card.content.presentation).toBe('notice')
		expect(card.content.variant).toBe('warning')
	})

	it('shows in the side column beside the Overview tab only, not in a tab body', () => {
		expect(card.tabs).toEqual(['overview'])
		expect(tabs.find((tab) => tab.id === 'overview').label).toBe('Overview')
		expect(tabs.filter((tab) => tabWidgets(tab).includes('case-type-check'))).toEqual([])
	})

	it('stays one compact box: one warning drawn, the count in a plural-free lead', () => {
		expect(card.content.limit).toBe(1)
		expect(card.content.lead).toBe('Warnings ({total}):')
	})
})
