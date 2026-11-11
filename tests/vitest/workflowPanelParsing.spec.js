/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for how the workflow editor's step and transition
 * panels read what they are given (StepConfigPanel / TransitionConfigPanel):
 * the JSON-or-array lists and the step config with its defaults.
 *
 * @spec openspec/changes/retrofit-2026-05-25-process-step-configuration/tasks.md
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({ NcButton: {} }))
vi.mock('vue-material-design-icons/Close.vue', () => ({ default: {} }))

const step = (await import('../../src/views/settings/components/StepConfigPanel.vue')).default.methods
const transition = (await import('../../src/views/settings/components/TransitionConfigPanel.vue')).default.methods

const LIST_PARSERS = [
	['step checklist', step.parseChecklist],
	['step actions', step.parseActions],
	['transition guards', transition.parseGuards],
	['transition actions', transition.parseActions],
]

const DEFAULT_ESCALATION = {
	trigger: 'preBreach',
	offset: 0,
	offsetUnit: 'businessDays',
	notifyRole: '',
	escalateToRole: '',
	openIncident: false,
}

describe.each(LIST_PARSERS)('the %s list', (_label, parse) => {
	it.each([undefined, null, '', 0, false])('is empty for %j', (value) => {
		expect(parse(value)).toEqual([])
	})

	it('parses a JSON string as is, and an unreadable one as empty', () => {
		expect(parse('[{"type":"a"}]')).toEqual([{ type: 'a' }])
		expect(parse('{"type":"a"}')).toEqual({ type: 'a' })
		expect(parse('not json')).toEqual([])
	})

	it('copies an array', () => {
		const list = [{ type: 'a' }]
		const copy = parse(list)
		expect(copy).toEqual(list)
		expect(copy).not.toBe(list)
		expect(copy[0]).toBe(list[0])
	})
})

describe('the step config', () => {
	it.each([undefined, null, '', 'not json', '"text"', 5, []])('defaults everything for %j', (config) => {
		expect(step.parseConfig(config)).toEqual({
			sla: { value: null, unit: '' },
			requiredFields: [],
			autoActions: [],
			escalationRule: DEFAULT_ESCALATION,
		})
	})

	it('reads a full config, from an object or a JSON string', () => {
		const config = {
			sla: { value: 5, unit: 'days' },
			requiredFields: ['a', 'b'],
			autoActions: [{ type: 'x' }],
			escalationRule: {
				trigger: 'breach',
				offset: 2,
				offsetUnit: 'hours',
				notifyRole: 'lead',
				escalateToRole: 'manager',
				openIncident: 'yes',
			},
		}
		const expected = { ...config, escalationRule: { ...config.escalationRule, openIncident: true } }
		expect(step.parseConfig(config)).toEqual(expected)
		expect(step.parseConfig(JSON.stringify(config))).toEqual(expected)
		expect(step.parseConfig(config).requiredFields).not.toBe(config.requiredFields)
		expect(step.parseConfig(config).autoActions).not.toBe(config.autoActions)
	})

	it('falls back field by field on unusable values', () => {
		expect(step.parseConfig({
			sla: { value: Number.NaN, unit: 3 },
			requiredFields: 'a',
			autoActions: { type: 'x' },
			escalationRule: { trigger: 1, offset: '2', offsetUnit: null, notifyRole: [], escalateToRole: {}, openIncident: 0 },
		})).toEqual({
			sla: { value: null, unit: '' },
			requiredFields: [],
			autoActions: [],
			escalationRule: DEFAULT_ESCALATION,
		})
		expect(step.parseConfig({ sla: 'x', escalationRule: 'x' })).toEqual({
			sla: { value: null, unit: '' },
			requiredFields: [],
			autoActions: [],
			escalationRule: DEFAULT_ESCALATION,
		})
		expect(step.parseConfig({ escalationRule: [] }).escalationRule).toEqual(DEFAULT_ESCALATION)
	})
})
