// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqVolgendeStap: the Opschorten form has "De zaak komt terug op" as a
 * date the handler can change (coordinator, 24 Oct), prefilled from the days
 * the applicant is given; a chosen date sets the days, so the suspend API
 * keeps taking days. The reason and the days sit side by side.
 *
 * The form is declared here and drawn by the library's
 * CnTransitionInputFields; the date arithmetic (today plus the days, whole
 * days back from a date, at least 1) is tested there
 * (nextcloud-vue tests/utils/transitionInputsDateFor.spec.js).
 *
 * @spec openspec/changes/suspend-return-date/specs/case-management/spec.md
 */

import { describe, expect, it } from 'vitest'
import { actForm } from '../../src/utils/caseActInputs.js'

describe('the Opschorten form', () => {
	it('asks the day the case comes back as a date set from the days given', () => {
		const days = actForm({ kind: 'gesture', id: 'suspend' }).inputs.find((input) => input.field === 'days')

		expect(days).toMatchObject({ widget: 'number', label: 'Days the applicant is given', testid: 'case-act-days-input' })
		expect(days.dateField).toEqual({
			label: 'The case comes back on',
			note: 'You pick the case up earlier if the answer comes in earlier.',
			testid: 'case-act-comes-back',
		})
		expect(days.preview).toBeUndefined()
	})

	it('labels the days above the field and draws no history line under the reason, as the board draws them', () => {
		const inputs = actForm({ kind: 'gesture', id: 'suspend' }).inputs
		expect(inputs.find((input) => input.field === 'days').labelOutside).toBe(true)
		expect(inputs.find((input) => input.field === 'reason').note).toBe('')
		const abort = actForm({ kind: 'gesture', id: 'abort' }).inputs.find((input) => input.field === 'reason')
		expect(abort.note).toBe('Goes into the history of the case.')
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
