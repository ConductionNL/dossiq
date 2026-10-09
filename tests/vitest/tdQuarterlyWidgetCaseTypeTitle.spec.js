// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The quarterly term report names each row by its case type's title.
 *
 * The report groups terms by the case type of their case (REQ-WTR-004) and
 * sends the title beside the key. A row reading "woo-verzoek" or a uuid is a
 * key, not a name; the title is what a manager recognises.
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const store = {
	quarterly: {
		perType: {
			'woo-verzoek': { title: 'Woo-verzoek', totaal: 3 },
			unresolved: { title: '', totaal: 1 },
		},
	},
	loadQuarterly: vi.fn(),
}

vi.mock('../../src/store/modules/termijnDashboard.js', () => ({
	useTermijnDashboardStore: () => store,
	currentQuarter: () => '2026-Q4',
}))

const TdQuarterlyWidget = (
	await import('../../src/views/termijn/TdQuarterlyWidget.vue')
).default

describe('TdQuarterlyWidget', () => {
	it('shows the case type title, and the key when there is no title', () => {
		const wrapper = shallowMount(TdQuarterlyWidget)
		const firstCells = wrapper
			.findAll('tbody tr')
			.map((row) => row.find('td').text())

		expect(firstCells).toEqual(['Woo-verzoek', 'unresolved'])
	})
})
