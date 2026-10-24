// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqVolgendeStap: the Opschorten form says when the case comes back,
 * read from the days the applicant is given, and sets the reason and the days
 * side by side.
 *
 * The form is declared here and drawn by the library's
 * CnTransitionInputFields; the preview's date arithmetic (today plus the
 * days, nothing without a positive number) is tested there
 * (nextcloud-vue tests/utils/transitionInputs.spec.js).
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

import { describe, expect, it } from 'vitest'
import { actForm } from '../../src/utils/caseActInputs.js'

describe('the Opschorten form', () => {
	it('says the day the case comes back, today plus the days given', () => {
		const days = actForm({ kind: 'gesture', id: 'suspend' }).inputs.find((input) => input.field === 'days')

		expect(days).toMatchObject({ widget: 'number', label: 'Days the applicant is given', testid: 'case-act-days-input' })
		expect(days.preview).toEqual({
			label: 'The case comes back on',
			template: '{today+days|date}',
			note: 'You pick the case up earlier if the answer comes in earlier.',
			testid: 'case-act-comes-back',
		})
	})

	it('pairs the reason and the days only on suspend', () => {
		expect(actForm({ kind: 'gesture', id: 'suspend' }).columns).toBe(2)
		expect(actForm({ kind: 'state', id: 'hold' }).columns).toBe(1)
	})

	it('asks a return date, not days, when holding', () => {
		const hold = actForm({ kind: 'state', id: 'hold' }).inputs
		expect(hold.find((input) => input.field === 'until')).toMatchObject({ widget: 'date', required: true, label: 'The case comes back on', testid: 'case-act-until-input' })
		expect(hold.some((input) => input.field === 'days')).toBe(false)
	})
})
