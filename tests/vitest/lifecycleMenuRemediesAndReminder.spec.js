// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqVolgendeStap: the remedies a case type declares close the menu
 * ("Bezwaar registreren"), shown or refused with the server's sentence, and
 * Suspend offers to remind the requester when the case type sets reminders.
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-from-the-case-page-req-rml-003
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */

import { describe, expect, it } from 'vitest'
import { actForm } from '../../src/utils/caseActInputs.js'
import { buildActsMenu, iconFor } from '../../src/utils/caseActsMenu.js'

const remedies = [
	{ id: 'objection', label: 'Register an objection', description: 'Only after a decision was made known.', available: false, reason: 'This case has no decision that was made known.' },
]

describe('remedies in the lifecycle menu', () => {
	it('lists each declared remedy last, with the server verdict and sentence', () => {
		const menu = buildActsMenu({
			transitions: [{ id: 't1', label: 'To Decision', guardsPassed: true }],
			state: { canSuspend: true, canReopen: false },
			acts: { acts: [], remedies },
		})
		const last = menu[menu.length - 1]

		expect(last).toMatchObject({
			kind: 'remedy',
			id: 'objection',
			label: 'Register an objection',
			explainer: 'Only after a decision was made known.',
			disabled: true,
			reason: 'This case has no decision that was made known.',
		})
		expect(iconFor(last)).toBe('ScaleBalance')
	})

	it('offers a remedy the server allows, and none when the case type declares none', () => {
		const open = buildActsMenu({ acts: { remedies: [{ ...remedies[0], available: true, reason: '' }] } })
		expect(open.find((entry) => entry.kind === 'remedy')).toMatchObject({ disabled: false, reason: '' })

		expect(buildActsMenu({ acts: { acts: [] } }).some((entry) => entry.kind === 'remedy')).toBe(false)
	})

	it('skips a remedy without an id', () => {
		const menu = buildActsMenu({ acts: { remedies: [{ label: 'Nameless' }] } })
		expect(menu.some((entry) => entry.kind === 'remedy')).toBe(false)
	})
})

describe('the reminder on Suspend', () => {
	it('offers to remind the requester when the case type sets reminders', () => {
		const form = actForm({ kind: 'gesture', id: 'suspend' }, { reminder: { afterDays: 5, maxCount: 2 } })
		const remind = form.inputs.find((input) => input.field === 'remindRequester')

		expect(remind).toMatchObject({
			widget: 'checkbox',
			required: false,
			label: 'Remind the requester if the answer does not come',
			testid: 'case-act-remind-requester',
		})
		expect(remind.note).toBe('After 5 days, at most 2 times, to the address the request went to. Each reminder goes on the timeline with a count. If it stays quiet after the last one, the case tells the team lead once.')
		expect(form.inputs[form.inputs.length - 1].field).toBe('remindRequester')
	})

	it('offers no reminder while the server names no reminder policy, nor on another act', () => {
		expect(actForm({ id: 'suspend' }).inputs.some((input) => input.field === 'remindRequester')).toBe(false)
		expect(actForm({ id: 'suspend' }, { reminder: { afterDays: 0, maxCount: 2 } }).inputs.some((input) => input.field === 'remindRequester')).toBe(false)
		expect(actForm({ id: 'hold' }, { reminder: { afterDays: 5, maxCount: 2 } }).inputs.some((input) => input.field === 'remindRequester')).toBe(false)
	})
})
