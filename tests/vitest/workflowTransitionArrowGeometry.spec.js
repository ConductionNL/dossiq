// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The workflow editor's transition arrow: its curve, arrowhead, label
 * position, selection colour and click events.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WorkflowTransitionArrow from '../../src/views/settings/components/WorkflowTransitionArrow.vue'

function arrow(props = {}) {
	return mount(WorkflowTransitionArrow, {
		props: { transition: { label: 'Approve' }, fromPos: { x: 0, y: 0 }, toPos: { x: 100, y: 40 }, ...props },
		attachTo: document.createElementNS('http://www.w3.org/2000/svg', 'svg'),
	})
}

describe('WorkflowTransitionArrow', () => {
	it('draws a cubic curve with its control points 30% along the horizontal span', () => {
		const wrapper = arrow()

		expect(wrapper.vm.arrowPath).toBe('M 0 0 C 30 0, 70 40, 100 40')
		const paths = wrapper.findAll('path')
		expect(paths).toHaveLength(2)
		expect(paths[0].attributes('d')).toBe('M 0 0 C 30 0, 70 40, 100 40')
		expect(paths[1].attributes('stroke-width')).toBe('16')
	})

	it('points the arrowhead along the line into the end point', () => {
		const wrapper = arrow({ fromPos: { x: 0, y: 0 }, toPos: { x: 100, y: 0 } })
		const [tip, left, right] = wrapper.vm.arrowheadPoints.split(' ').map((p) => p.split(',').map(Number))

		expect(tip).toEqual([100, 0])
		expect(left[0]).toBeCloseTo(100 - 10 * Math.cos(Math.PI / 6))
		expect(left[1]).toBeCloseTo(10 * Math.sin(Math.PI / 6))
		expect(right[0]).toBeCloseTo(100 - 10 * Math.cos(Math.PI / 6))
		expect(right[1]).toBeCloseTo(-10 * Math.sin(Math.PI / 6))
	})

	it('places the label above the midpoint and leaves it out when there is none', () => {
		const text = arrow().find('text')
		expect(text.text()).toBe('Approve')
		expect(text.attributes('x')).toBe('50')
		expect(text.attributes('y')).toBe('12')

		expect(arrow({ transition: {} }).find('text').exists()).toBe(false)
	})

	it('colours a selected arrow with the primary colour', () => {
		const selected = arrow({ selected: true })
		expect(selected.classes()).toContain('workflow-transition--selected')
		expect(selected.find('polygon').attributes('fill')).toBe('var(--color-primary)')

		const plain = arrow()
		expect(plain.classes()).not.toContain('workflow-transition--selected')
		expect(plain.find('polygon').attributes('fill')).toBe('var(--color-text-maxcontrast)')
	})

	it('emits click and dblclick', async () => {
		const wrapper = arrow()
		await wrapper.trigger('click')
		await wrapper.trigger('dblclick')

		expect(Object.keys(wrapper.emitted())).toEqual(expect.arrayContaining(['click', 'dblclick']))
		expect(wrapper.emitted('click')).toHaveLength(1)
		expect(wrapper.emitted('dblclick')).toHaveLength(1)
	})
})
