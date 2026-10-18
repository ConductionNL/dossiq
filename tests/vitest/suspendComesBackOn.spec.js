// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqVolgendeStap: the Opschorten form says when the case comes back,
 * read from the days the applicant is given, and sets the reason and the days
 * side by side.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { formatDate } from '../../src/utils/caseHelpers.js'

vi.mock('@nextcloud/dialogs', () => ({
	showWarning: vi.fn(),
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))

const CaseActFields = (await import('../../src/components/case/CaseActFields.vue')).default

/**
 * The suspend form with the given days.
 *
 * @param {string} days The days typed.
 * @return {object} The mounted wrapper.
 */
function suspendWith(days) {
	return shallowMount(CaseActFields, {
		props: {
			act: { kind: 'gesture', id: 'suspend' },
			inputs: { reason: true, result: false, until: false, days: true },
			days,
		},
	})
}

describe('the Opschorten form', () => {
	it('says the day the case comes back, today plus the days given', () => {
		const expected = new Date()
		expected.setDate(expected.getDate() + 14)

		const line = suspendWith('14').find('[data-testid="case-act-comes-back-date"]')
		expect(line.text()).toBe(formatDate(expected.toISOString()))
	})

	it('says nothing without days', () => {
		expect(suspendWith('').find('[data-testid="case-act-comes-back"]').exists()).toBe(false)
		expect(suspendWith('0').find('[data-testid="case-act-comes-back"]').exists()).toBe(false)
	})

	it('pairs the reason and the days only on suspend', () => {
		expect(suspendWith('14').classes()).toContain('case-act-fields--paired')
		const hold = shallowMount(CaseActFields, {
			props: {
				act: { kind: 'state', id: 'hold' },
				inputs: { reason: true, result: false, until: true, days: false },
			},
		})
		expect(hold.classes()).not.toContain('case-act-fields--paired')
	})
})
