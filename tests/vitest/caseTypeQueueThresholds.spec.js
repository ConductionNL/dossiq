// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type's own queue thresholds on the General tab.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { describe, expect, it } from 'vitest'
import GeneralTab from '../../src/views/settings/tabs/GeneralTab.vue'
import { readThresholdOverride } from '../../src/utils/queueUrgencySettings.js'

/**
 * Run the tab's own update method against a recording `this`.
 *
 * @param {string} field The field.
 * @param {string} raw What was typed.
 * @param {number} max The bound.
 * @return {{emitted: Array, errors: object}} What it emitted and the errors it set.
 */
function typeInto(field, raw, max) {
	const emitted = []
	const self = { thresholdErrors: {}, $emit: (...args) => emitted.push(args) }
	GeneralTab.methods.updateThreshold.call(self, field, raw, max)
	return { emitted, errors: self.thresholdErrors }
}

describe('readThresholdOverride', () => {
	it('reads empty as no override', () => {
		expect(readThresholdOverride('', 60)).toEqual({
			value: undefined,
			error: '',
		})
		expect(readThresholdOverride(undefined, 60)).toEqual({
			value: undefined,
			error: '',
		})
	})

	it('reads a whole number in bounds', () => {
		expect(readThresholdOverride('10', 60)).toEqual({ value: 10, error: '' })
	})

	it('refuses a number out of bounds', () => {
		expect(readThresholdOverride('61', 60).error).toBe(
			'Enter a whole number from 0 to 60.',
		)
	})
})

describe('GeneralTab queue thresholds', () => {
	// @spec openspec/specs/case-types/spec.md#scenario-the-editor-offers-both-fields-empty-by-default
	it('shows an unset threshold as an empty field', () => {
		expect(GeneralTab.methods.thresholdText(undefined)).toBe('')
		expect(GeneralTab.methods.thresholdText(null)).toBe('')
		expect(GeneralTab.methods.thresholdText(10)).toBe('10')
	})

	it('emits the number for the case type form', () => {
		expect(typeInto('queueCriticalDays', '10', 60).emitted).toEqual([
			['update', 'queueCriticalDays', 10],
		])
	})

	it('emits undefined for an empty field, so the default applies', () => {
		expect(typeInto('queueWarningDays', '', 120).emitted).toEqual([
			['update', 'queueWarningDays', undefined],
		])
	})

	it('emits nothing for a refused value and shows why', () => {
		const result = typeInto('queueWarningDays', '500', 120)
		expect(result.emitted).toEqual([])
		expect(result.errors.queueWarningDays).toBe(
			'Enter a whole number from 0 to 120.',
		)
	})
})
