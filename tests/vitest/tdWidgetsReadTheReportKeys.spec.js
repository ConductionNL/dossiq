// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The term widgets read the keys the report actually sends.
 *
 * DeadlineReportingService sends the dwangsom total in cents
 * (`dwangsomTotalCents`), the within-term share as `binnenTermijnPct` and the
 * average duration as `gemiddeldeDoorlooptijdDagen`. The widgets read
 * `dwangsomTotal`, `binnenTermijnPercent` and `gemiddeldeDoorlooptijd`, so
 * every one of those cells showed 0 whatever the report said.
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */

import { mount, shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const store = {
	kpi: {
		totalZaken: 4,
		withinTermijnPercent: 75,
		avgDurationDays: 12.5,
		overrunCount: 1,
		dwangsomTotalCents: 123450,
		lastUpdated: '2026-10-11T10:00:00+02:00',
	},
	quarterly: {
		perType: {
			'woo-verzoek': {
				title: 'Woo-verzoek',
				totaal: 4,
				binnenTermijnPct: 75,
				overschrijdingen: 1,
				gemiddeldeDoorlooptijdDagen: 12.5,
				verlengingen: 2,
				ingebrekestellingen: 1,
				dwangsomTotalCents: 123450,
			},
		},
	},
	loadKpi: vi.fn(),
	loadQuarterly: vi.fn(),
}

vi.mock('@conduction/nextcloud-vue', () => ({
	CnStatsBlock: {
		name: 'CnStatsBlock',
		template: '<div class="stat"><slot name="value" /></div>',
	},
	CnKpiGrid: { name: 'CnKpiGrid', template: '<div><slot /></div>' },
}))

vi.mock('../../src/store/modules/termijnDashboard.js', () => ({
	useTermijnDashboardStore: () => store,
	currentQuarter: () => '2026-Q4',
}))

const TdQuarterlyWidget = (
	await import('../../src/views/termijn/TdQuarterlyWidget.vue')
).default
const TdKpiWidget = (await import('../../src/views/termijn/TdKpiWidget.vue')).default

function euro(n) {
	return n.toLocaleString('nl-NL', { style: 'currency', currency: 'EUR' })
}

describe('term widgets', () => {
	it('the quarterly row shows the share, the duration and the dwangsom in euros', () => {
		const wrapper = shallowMount(TdQuarterlyWidget)
		const cells = wrapper.findAll('tbody tr td').map((td) => td.text())

		expect(cells[2]).toBe('75.0 %')
		expect(cells[4]).toBe('12.5')
		expect(cells[7]).toBe(euro(1234.5))
	})

	it('the KPI block shows the dwangsom total in euros, not cents', () => {
		const wrapper = mount(TdKpiWidget)

		expect(wrapper.text()).toContain(euro(1234.5))
	})
})
