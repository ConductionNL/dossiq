// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Plan something (board DqUwWachtrij) draws the boards' dialog header: a kicker
 * naming where the item lands, the title and a sentence under it, and a footer
 * with Cancel beside Plan it.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: defineComponent({
		name: 'CnDialog',
		props: { name: String, eyebrow: String, subtitle: String },
		emits: ['closing'],
		render() {
			return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
		},
	}),
}))

vi.mock('../../src/services/personalQueueApi.js', () => ({
	planItem: vi.fn(() => Promise.resolve({})),
}))

const { default: PlanItemDialog } = await import('../../src/dialogs/PlanItemDialog.vue')

describe('PlanItemDialog', () => {
	it('names where the item lands above the title and explains it below', () => {
		const wrapper = shallowMount(PlanItemDialog, { global: { stubs: { CnDialog: false } } })
		const dialog = wrapper.findComponent({ name: 'CnDialog' })

		expect(dialog.props('name')).toBe('Plan an item')
		expect(dialog.props('eyebrow')).toBe('Your queue · own task')
		expect(dialog.props('subtitle')).toBe('This goes on your own calendar and on your queue. It is not a case.')
	})

	it('closes on Cancel without planning anything', async () => {
		const wrapper = shallowMount(PlanItemDialog, {
			global: {
				stubs: {
					CnDialog: false,
					NcButton: defineComponent({
						name: 'NcButton',
						emits: ['click'],
						render() {
							return h('button', { onClick: () => this.$emit('click') }, this.$slots.default?.())
						},
					}),
				},
			},
		})

		await wrapper.find('[data-testid="plan-item-cancel"]').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.emitted('planned')).toBeUndefined()
	})
})
