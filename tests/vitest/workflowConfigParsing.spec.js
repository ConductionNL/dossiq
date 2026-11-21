/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The workflow editor's step and transition panels read lists that arrive as
 * JSON or as arrays, and a step's config with a default for every field.
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-process-step-configuration/tasks.md
 */

import { describe, expect, it } from 'vitest'
import { parseJsonList, parseStepConfig } from '../../src/views/settings/components/workflowConfigParsing.js'

const DEFAULTS = {
	sla: { value: null, unit: '' },
	requiredFields: [],
	autoActions: [],
	escalationRule: {
		trigger: 'preBreach',
		offset: 0,
		offsetUnit: 'businessDays',
		notifyRole: '',
		escalateToRole: '',
		openIncident: false,
	},
}

describe('parseJsonList', () => {
	it.each([null, undefined, '', 0, false])('answers an empty list for %p', (value) => {
		expect(parseJsonList(value)).toEqual([])
	})

	it('parses a JSON string', () => {
		expect(parseJsonList('["a",{"b":1}]')).toEqual(['a', { b: 1 }])
	})

	it('answers an empty list for unreadable JSON', () => {
		expect(parseJsonList('[not json')).toEqual([])
	})

	it('copies an array instead of handing back the same one', () => {
		const stored = ['x', 'y']
		const parsed = parseJsonList(stored)

		expect(parsed).toEqual(['x', 'y'])
		expect(parsed).not.toBe(stored)
		parsed.push('z')
		expect(stored).toEqual(['x', 'y'])
	})
})

describe('parseStepConfig', () => {
	it.each([null, undefined, '', 'not json', '42', 'null', 7])('defaults every field for %p', (config) => {
		expect(parseStepConfig(config)).toEqual(DEFAULTS)
	})

	it('reads a stored config object', () => {
		const config = {
			sla: { value: 5, unit: 'businessDays' },
			requiredFields: ['subject'],
			autoActions: [{ type: 'notify' }],
			escalationRule: {
				trigger: 'postBreach',
				offset: 2,
				offsetUnit: 'hours',
				notifyRole: 'coordinator',
				escalateToRole: 'manager',
				openIncident: 1,
			},
		}

		expect(parseStepConfig(config)).toEqual({
			sla: { value: 5, unit: 'businessDays' },
			requiredFields: ['subject'],
			autoActions: [{ type: 'notify' }],
			escalationRule: {
				trigger: 'postBreach',
				offset: 2,
				offsetUnit: 'hours',
				notifyRole: 'coordinator',
				escalateToRole: 'manager',
				openIncident: true,
			},
		})
	})

	it('reads the same config from its JSON', () => {
		const json = JSON.stringify({ sla: { value: 0, unit: 'days' }, requiredFields: ['a'] })

		expect(parseStepConfig(json)).toEqual({
			...DEFAULTS,
			sla: { value: 0, unit: 'days' },
			requiredFields: ['a'],
		})
	})

	it('drops an SLA value that is not a finite number and a unit that is not a string', () => {
		expect(parseStepConfig({ sla: { value: '5', unit: 3 } }).sla).toEqual({ value: null, unit: '' })
		expect(parseStepConfig({ sla: { value: Infinity, unit: 'days' } }).sla).toEqual({ value: null, unit: 'days' })
		expect(parseStepConfig({ sla: { value: NaN } }).sla).toEqual({ value: null, unit: '' })
	})

	it('answers lists only for stored arrays, as copies', () => {
		const requiredFields = ['subject']
		const parsed = parseStepConfig({ requiredFields, autoActions: '["notify"]' })

		expect(parsed.requiredFields).toEqual(['subject'])
		expect(parsed.requiredFields).not.toBe(requiredFields)
		expect(parsed.autoActions).toEqual([])
	})

	it('defaults each escalation field that is missing or of the wrong type', () => {
		expect(parseStepConfig({
			escalationRule: { trigger: 1, offset: '3', offsetUnit: null, notifyRole: ['x'], escalateToRole: 'manager', openIncident: 0 },
		}).escalationRule).toEqual({
			...DEFAULTS.escalationRule,
			escalateToRole: 'manager',
		})
		expect(parseStepConfig({ escalationRule: 'preBreach' }).escalationRule).toEqual(DEFAULTS.escalationRule)
	})
})
