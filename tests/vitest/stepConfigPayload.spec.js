/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the step panel's `buildConfigPayload`
 * (StepConfigPanel): which parts of the edited config are saved, and when
 * nothing is.
 *
 * @spec openspec/changes/retrofit-2026-05-25-process-step-configuration/tasks.md
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({ NcButton: {} }))
vi.mock('vue-material-design-icons/Close.vue', () => ({ default: {} }))

const { methods } = (await import('../../src/views/settings/components/StepConfigPanel.vue')).default

const RULE = { trigger: 'breach', offset: 1, offsetUnit: 'days', notifyRole: 'a', escalateToRole: 'b', openIncident: true }

/**
 * Build the payload for an edited config.
 *
 * @param {object} localConfig The edited config.
 * @param {boolean} escalationEnabled Whether escalation is switched on.
 * @return {object|undefined} The payload.
 */
function payload(localConfig, escalationEnabled = false) {
	return methods.buildConfigPayload.call({ localConfig, escalationEnabled })
}

const EMPTY = { sla: { value: null, unit: '' }, requiredFields: [], autoActions: [], escalationRule: RULE }

describe('step config payload', () => {
	it('saves nothing for an untouched config', () => {
		expect(payload(EMPTY)).toBeUndefined()
	})

	it('saves every filled part', () => {
		const actions = [{ type: 'x' }]
		const out = payload({
			sla: { value: 0, unit: 'days' },
			requiredFields: ['a', ' ', '', 3, ' b '],
			autoActions: actions,
			escalationRule: RULE,
		}, true)
		expect(out).toEqual({
			sla: { value: 0, unit: 'days' },
			requiredFields: ['a', ' b '],
			autoActions: actions,
			escalationRule: RULE,
		})
		expect(Object.keys(out)).toEqual(['sla', 'requiredFields', 'autoActions', 'escalationRule'])
		expect(out.autoActions).toBe(actions)
		expect(out.escalationRule).not.toBe(RULE)
	})

	it.each([
		[{ value: 5, unit: '' }],
		[{ value: null, unit: 'days' }],
		[{ value: undefined, unit: 'days' }],
	])('leaves out an incomplete service level %j', (sla) => {
		expect(payload({ ...EMPTY, sla })).toBeUndefined()
	})

	it('saves the escalation rule alone when switched on', () => {
		expect(payload(EMPTY, true)).toEqual({ escalationRule: RULE })
	})

	it('leaves out auto actions that are not a non-empty list', () => {
		expect(payload({ ...EMPTY, autoActions: 'x' })).toBeUndefined()
	})
})
