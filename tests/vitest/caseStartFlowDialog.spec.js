// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Starting a flow from a case: where the case id comes from, what loads,
 * the one flow chosen for you, the run request, and refusals kept in the
 * dialog as sentences rather than codes.
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockGet = vi.fn()
const mockPost = vi.fn()
const mockEmit = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/event-bus', () => ({ emit: (...a) => mockEmit(...a), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: defineComponent({
		name: 'CnDialog',
		emits: ['closing'],
		render() {
			return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
		},
	}),
}))

const { default: CaseStartFlowDialog } = await import('../../src/dialogs/CaseStartFlowDialog.vue')

const STUBS = {
	NcButton: { emits: ['click'], props: ['disabled'], template: '<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcCheckboxRadioSwitch: { name: 'NcCheckboxRadioSwitch', props: ['modelValue', 'value'], emits: ['update:modelValue'], template: '<label class="radio"><slot /></label>' },
	NcLoadingIcon: { template: '<span class="loading" />' },
}

const FLOWS = [
	{ id: 'f-1', title: 'Zienswijze derde vragen', description: 'Stuurt een brief' },
	{ id: 'f-2', title: 'Verdaging aankondigen' },
]

/**
 * Mount the dialog and let it load.
 *
 * @param {object} props Its props.
 * @param {object} route The current route.
 * @return {Promise<object>} The wrapper.
 */
async function mountDialog(props = {}, route = { params: {} }) {
	const wrapper = mount(CaseStartFlowDialog, { props, global: { stubs: STUBS, mocks: { $route: route } } })
	await flushPromises()
	return wrapper
}

describe('CaseStartFlowDialog', () => {
	beforeEach(() => vi.clearAllMocks())

	it('reads the case from the route when no case id is passed', async () => {
		mockGet.mockResolvedValue({ data: { results: FLOWS } })

		const wrapper = await mountDialog({}, { params: { id: 'case/7' } })

		expect(mockGet).toHaveBeenCalledWith('/apps/dossiq/api/case/case%2F7/startable-flows')
		expect(wrapper.findAll('.radio').map((r) => r.text())).toEqual(['Zienswijze derde vragen', 'Verdaging aankondigen'])
		expect(wrapper.text()).toContain('Stuurt een brief')
		expect(wrapper.vm.chosen).toBe('')
		expect(wrapper.find('[data-testid="case-start-flow-confirm"]').attributes('disabled')).toBeDefined()
	})

	it('loads nothing without any case id and says no flow can be started', async () => {
		const wrapper = await mountDialog({}, undefined)

		expect(mockGet).not.toHaveBeenCalled()
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.find('[data-testid="case-start-flow-empty"]').exists()).toBe(true)
	})

	it('chooses the only flow for you', async () => {
		mockGet.mockResolvedValue({ data: { results: [FLOWS[1]] } })

		const wrapper = await mountDialog({ caseId: 'c-1' })

		expect(wrapper.vm.chosen).toBe('f-2')
		expect(wrapper.find('[data-testid="case-start-flow-confirm"]').attributes('disabled')).toBeUndefined()
	})

	it('treats a body without a result list as no flows', async () => {
		mockGet.mockResolvedValue({ data: { results: 'nope' } })

		const wrapper = await mountDialog({ caseId: 'c-1' })

		expect(wrapper.vm.flows).toEqual([])
		expect(wrapper.find('[data-testid="case-start-flow-empty"]').exists()).toBe(true)
	})

	it('shows a failed load as the sentence for its code', async () => {
		mockGet.mockRejectedValue({ response: { data: { code: 'case_not_found' } } })

		const wrapper = await mountDialog({ caseId: 'c-1' })

		expect(wrapper.find('[data-testid="case-start-flow-error"]').text()).toBe('This case could not be read.')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('runs the chosen flow on the case, refreshes the page and closes', async () => {
		mockGet.mockResolvedValue({ data: { results: FLOWS } })
		mockPost.mockResolvedValue({ data: {} })
		const wrapper = await mountDialog({ caseId: 'c-1' })

		wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })[0].vm.$emit('update:modelValue', 'f-1')
		await wrapper.vm.$nextTick()
		await wrapper.find('[data-testid="case-start-flow-confirm"]').trigger('click')
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith('/apps/openregister/api/flows/f-1/run', {
			subject: { uuid: 'c-1', register: 'dossiq', schema: 'case' },
		})
		expect(mockEmit).toHaveBeenCalledWith('cn:page:refresh')
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.vm.busy).toBe(false)
	})

	it('keeps a refused run in the dialog, with the generic line for an unknown code', async () => {
		mockGet.mockResolvedValue({ data: { results: [FLOWS[0]] } })
		mockPost.mockRejectedValue({ response: { data: { code: 'something_else' } } })
		const wrapper = await mountDialog({ caseId: 'c-1' })

		await wrapper.vm.run()
		await flushPromises()

		expect(wrapper.vm.error).toBe('This did not work. Try again.')
		expect(mockEmit).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(wrapper.vm.busy).toBe(false)
	})

	it('does not run without a choice, and closes on cancel', async () => {
		mockGet.mockResolvedValue({ data: { results: FLOWS } })
		const wrapper = await mountDialog({ caseId: 'c-1' })

		await wrapper.vm.run()
		expect(mockPost).not.toHaveBeenCalled()

		await wrapper.find('[data-testid="case-start-flow-cancel"]').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
