// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lens cell renders three states that never look alike.
 *
 * The reader is covered by `lensValue.spec.js`; what this adds is the half a
 * formatter could not do. A formatter returns a string, so it can shape a
 * value but it cannot say that the value is being kept from you. The whole
 * reason this column declares a cell WIDGET is that the withheld marker must
 * arrive on screen as a sentence rather than as a blank, and a blank is
 * exactly what every default does with it.
 *
 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import LensedValueCell from '../../src/components/cells/LensedValueCell.vue'

/**
 * Mount the cell for one lens value.
 *
 * @param {unknown} value The resolved lens value.
 * @return {object} The wrapper.
 */
const cell = (value) => mount(LensedValueCell, { props: { value } })

describe('LensedValueCell', () => {
	it('shows the value when there is one', () => {
		const wrapper = cell('Pand 1234')
		expect(wrapper.attributes('data-state')).toBe('value')
		expect(wrapper.text()).toContain('Pand 1234')
	})

	it('says withheld rather than leaving the cell empty', () => {
		// Blank reads as "this case is about nothing", which is a different
		// statement and a wrong one, and it is wrong most exactly where the
		// information is sensitive.
		const wrapper = cell({ '@withheld': true, reason: 'access' })
		expect(wrapper.attributes('data-state')).toBe('withheld')
		expect(wrapper.text()).toContain('Withheld')
		expect(wrapper.text()).not.toContain('[object Object]')
	})

	it('gives the reason on hover', () => {
		const wrapper = cell({ '@withheld': true, reason: 'access' })
		expect(wrapper.attributes('title')).toContain('may not open')
	})

	it('draws an em dash for a link that resolves to nothing', () => {
		for (const nothing of [null, undefined, '']) {
			const wrapper = cell(nothing)
			expect(wrapper.attributes('data-state')).toBe('empty')
			expect(wrapper.text()).toBe('—')
		}
	})

	it('tells withheld and empty apart in the markup, not only in the text', () => {
		// The two states differ in presence and in words, never in colour alone
		// (WCAG 2.2 SC 1.4.1), and the state is on the element so an e2e and a
		// screen reader can both reach it.
		expect(cell(null).attributes('data-state')).not.toBe(
			cell({ '@withheld': true }).attributes('data-state'),
		)
	})

	it('carries no hover text when nothing is being withheld', () => {
		expect(cell('Pand 1234').attributes('title')).toBe('')
		expect(cell(null).attributes('title')).toBe('')
	})

	it('shows a value that is falsy but real', () => {
		// `0` and `false` are values. A truthiness check would draw an em dash
		// over both, which says the object has no status when it has one.
		expect(cell(0).attributes('data-state')).toBe('value')
		expect(cell(false).attributes('data-state')).toBe('value')
	})
})
