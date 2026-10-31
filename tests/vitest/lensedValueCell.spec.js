// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A lens cell renders three states that never look alike.
 *
 * The Objects tab's lens columns declare no widget: the library cell reads
 * OpenRegister's withheld marker before any other path. What this holds is
 * that the marker arrives on screen as a sentence rather than as a blank or
 * as `[object Object]`, which is what every other default does with it.
 *
 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CnCellRenderer from '@conduction/nextcloud-vue/src/components/CnCellRenderer/CnCellRenderer.vue'

/**
 * Mount the library cell for one lens value, as a plain text column.
 *
 * @param {unknown} value The resolved lens value.
 * @return {object} The wrapper.
 */
const cell = (value) => mount(CnCellRenderer, { props: { value, property: { type: 'string' } } })

describe('a lens column cell', () => {
	it('shows the value when there is one', () => {
		const wrapper = cell('Pand 1234')
		expect(wrapper.find('[data-testid="cn-cell-withheld"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('Pand 1234')
	})

	it('says withheld rather than leaving the cell empty', () => {
		// Blank reads as "this case is about nothing", which is a different
		// statement and a wrong one, and it is wrong most exactly where the
		// information is sensitive.
		const wrapper = cell({ '@withheld': true, reason: 'access' })
		expect(wrapper.find('[data-testid="cn-cell-withheld"]').text()).toBe('Withheld')
		expect(wrapper.text()).not.toContain('[object Object]')
	})

	it('gives the reason on hover', () => {
		const wrapper = cell({ '@withheld': true, reason: 'access' })
		expect(wrapper.find('[data-testid="cn-cell-withheld"]').attributes('title')).toContain('may not open')
	})

	it('draws a dash for a link that resolves to nothing', () => {
		for (const nothing of [null, undefined, '']) {
			const wrapper = cell(nothing)
			expect(wrapper.find('[data-testid="cn-cell-withheld"]').exists()).toBe(false)
			expect(wrapper.text()).toBe('—')
		}
	})

	it('shows a value that is falsy but real', () => {
		// `0` is a value. A truthiness check would draw a dash over it, which
		// says the object has no status when it has one.
		expect(cell(0).text()).toBe('0')
	})
})
