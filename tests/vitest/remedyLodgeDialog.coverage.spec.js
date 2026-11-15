// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The remedy lodging dialog driven through its own controls: the form fields
 * fill the lodging the server receives, the dialog's close and the cancel and
 * "stay on this case" buttons close it, an unresolved `@objectId` falls back
 * to the route, a cleared date blocks saving, and the default props (no
 * channels, the CaseDetail page) hold when the action passes none.
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-from-the-case-page-req-rml-003
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const axios = (await import('@nextcloud/axios')).default
const { emit } = await import('@nextcloud/event-bus')
const { default: RemedyLodgeDialog } = await import('../../src/dialogs/RemedyLodgeDialog.vue')

const FIELD = {
	props: ['modelValue'],
	emits: ['update:modelValue'],
	template: '<div class="field" />',
}

const STUBS = {
	NcDialog: {
		props: ['name'],
		emits: ['closing'],
		template: '<div><h2 data-testid="title">{{ name }}</h2><button data-testid="x" @click="$emit(\'closing\')" /><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcDateTimePicker: { ...FIELD, name: 'NcDateTimePicker' },
	NcSelect: { ...FIELD, name: 'NcSelect', props: ['modelValue', 'options'] },
	NcTextArea: { ...FIELD, name: 'NcTextArea' },
}

/**
 * Mount the dialog.
 *
 * @param {object} props Its props.
 * @param {object} route The current route.
 * @return {Promise<{wrapper: object, push: Function}>} The dialog and the router's push.
 */
async function mountDialog(props = {}, route = { params: { id: 'route-case' } }) {
	const push = vi.fn()
	const wrapper = mount(RemedyLodgeDialog, {
		props,
		global: { stubs: STUBS, mocks: { $router: { push }, $route: route } },
	})
	await flushPromises()
	return { wrapper, push }
}

/**
 * The stub carrying a data-testid.
 *
 * @param {object} wrapper The dialog.
 * @param {string} id The data-testid.
 * @return {object} The stub's wrapper.
 */
function field(wrapper, id) {
	return wrapper.findAllComponents('.field').find((c) => c.attributes('data-testid') === id)
}

beforeEach(() => {
	axios.post.mockReset()
	emit.mockReset()
})

describe('RemedyLodgeDialog controls', () => {
	it('sends what was entered in each field, to the route case when the id is an unresolved token', async () => {
		const { wrapper } = await mountDialog({
			caseId: '@objectId',
			channels: [{ value: 'brief', label: 'Letter' }],
		})
		axios.post.mockResolvedValue({ data: { caseId: 'new-1', deadline: 'soon', isTimely: true } })

		field(wrapper, 'remedy-lodge-received').vm.$emit('update:modelValue', new Date(2026, 2, 5, 23, 50))
		field(wrapper, 'remedy-lodge-channel').vm.$emit('update:modelValue', wrapper.vm.channelOptions[0])
		field(wrapper, 'remedy-lodge-grounds').vm.$emit('update:modelValue', ' Te laat besloten ')
		field(wrapper, 'remedy-lodge-relief').vm.$emit('update:modelValue', ' Herroepen ')
		await flushPromises()

		const save = wrapper.find('[data-testid="remedy-lodge-save"]')
		expect(save.attributes('disabled')).toBeUndefined()
		await save.trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/dossiq/api/cases/route-case/remedy', {
			receivedDate: '2026-03-05',
			receivedChannel: 'brief',
			grounds: 'Te laat besloten',
			requestedRelief: 'Herroepen',
		})
		expect(emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(wrapper.find('[data-testid="title"]').text()).toBe('Lodge a remedy')
		// An unparseable deadline is shown as the server sent it.
		expect(wrapper.find('[data-testid="remedy-lodge-deadline"]').text()).toBe('The term of the new case ends on soon.')
	})

	it('blocks saving while the received date is cleared or invalid', async () => {
		const { wrapper } = await mountDialog({ caseId: 'c-1', channels: [{ value: 'email' }] })
		wrapper.vm.channel = wrapper.vm.channelOptions[0]
		wrapper.vm.grounds = 'Reden'
		await flushPromises()
		expect(wrapper.vm.channelOptions).toEqual([{ id: 'email', label: 'email' }])
		expect(wrapper.find('[data-testid="remedy-lodge-save"]').attributes('disabled')).toBeUndefined()

		field(wrapper, 'remedy-lodge-received').vm.$emit('update:modelValue', null)
		await flushPromises()
		expect(wrapper.find('[data-testid="remedy-lodge-save"]').attributes('disabled')).toBeDefined()

		field(wrapper, 'remedy-lodge-received').vm.$emit('update:modelValue', new Date('not a day'))
		await flushPromises()
		expect(wrapper.find('[data-testid="remedy-lodge-save"]').attributes('disabled')).toBeDefined()
	})

	it('offers no channels and cannot save without a case anywhere', async () => {
		const { wrapper } = await mountDialog({}, {})
		expect(wrapper.vm.channelOptions).toEqual([])
		expect(wrapper.vm.targetCaseId).toBe('')
		wrapper.vm.channel = { id: 'x' }
		wrapper.vm.grounds = 'Reden'
		await flushPromises()
		expect(wrapper.find('[data-testid="remedy-lodge-save"]').attributes('disabled')).toBeDefined()
	})

	it('closes from the dialog itself and from Cancel without posting', async () => {
		const { wrapper } = await mountDialog({ caseId: 'c-1' })
		await wrapper.find('[data-testid="x"]').trigger('click')
		await wrapper.find('[data-testid="remedy-lodge-cancel"]').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('stays on this case after lodging, and opens the default case page when asked', async () => {
		const { wrapper, push } = await mountDialog({ caseId: 'c-1', channels: [{ value: 'balie', label: 'Desk' }] })
		axios.post.mockResolvedValue({ data: { caseId: 'new-9', isTimely: true } })
		wrapper.vm.channel = wrapper.vm.channelOptions[0]
		wrapper.vm.grounds = 'Reden'
		await flushPromises()
		await wrapper.find('[data-testid="remedy-lodge-save"]').trigger('click')
		await flushPromises()

		await wrapper.find('[data-testid="remedy-lodge-close"]').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(push).not.toHaveBeenCalled()

		await wrapper.find('[data-testid="remedy-lodge-open"]').trigger('click')
		expect(push).toHaveBeenCalledWith({ name: 'CaseDetail', params: { id: 'new-9' } })
		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})
