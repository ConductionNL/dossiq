// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The advice request dialog driven through its own controls rather than its
 * data: the kind switch swaps the adviser picker for the organisation field,
 * the picker's search reaches the sharee lookup, every field's input lands in
 * the posted request, the form's own submit posts it, Cancel and the dialog's
 * close emit `close`, and a submit without an adviser posts nothing.
 *
 * @spec openspec/specs/advice-management/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AdviceRequestDialog from '../../src/dialogs/AdviceRequestDialog.vue'

const mockGet = vi.fn()
const mockPost = vi.fn()
const mockEmit = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u, generateOcsUrl: (u) => '/ocs/v2.php/' + u }))
vi.mock('@nextcloud/event-bus', () => ({ emit: (...a) => mockEmit(...a), subscribe: vi.fn(), unsubscribe: vi.fn() }))

const FIELD = { props: ['modelValue'], emits: ['update:modelValue'], template: '<div class="field" />' }

const STUBS = {
	CnDialog: {
		props: ['name', 'eyebrow'],
		emits: ['closing'],
		template: '<div><p data-testid="eyebrow">{{ eyebrow }}</p><button data-testid="x" @click="$emit(\'closing\')" /><slot /><slot name="actions" /></div>',
	},
	CnSegmentedControl: { ...FIELD, props: ['modelValue', 'options'] },
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcDateTimePicker: FIELD,
	NcSelect: { ...FIELD, props: ['modelValue', 'options', 'loading', 'filterBy'], emits: ['update:modelValue', 'search'] },
	NcTextArea: FIELD,
	NcTextField: FIELD,
	NcNoteCard: { props: ['heading'], template: '<div class="note">{{ heading }} <slot /></div>' },
}

/**
 * Mount the dialog with the library's controls stubbed.
 *
 * @param {object} props Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountDialog(props) {
	mockGet.mockResolvedValueOnce({ data: { identifier: '2026-0110' } })
	const wrapper = mount(AdviceRequestDialog, {
		props,
		global: { stubs: STUBS, mocks: { $route: { params: { id: 'case-route' } } } },
	})
	await flushPromises()
	return wrapper
}

/**
 * The stub with this data-testid.
 *
 * @param {object} wrapper The dialog.
 * @param {string} id The data-testid.
 * @return {object|undefined} The stub's wrapper.
 */
function control(wrapper, id) {
	return wrapper.findAllComponents('.field').find((c) => c.attributes('data-testid') === id)
}

describe('AdviceRequestDialog controls', () => {
	beforeEach(() => vi.clearAllMocks())

	it('switches to external advice and posts every field it was given through the form', async () => {
		mockPost.mockResolvedValue({ data: { id: 'adv-7' } })
		const wrapper = await mountDialog({ caseId: 'case-1' })
		expect(wrapper.find('[data-testid="eyebrow"]').text()).toBe('Advice · 2026-0110')
		expect(control(wrapper, 'advice-request-adviser')).toBeDefined()

		control(wrapper, 'advice-request-kind').vm.$emit('update:modelValue', 'external')
		await flushPromises()
		expect(control(wrapper, 'advice-request-adviser')).toBeUndefined()

		control(wrapper, 'advice-request-organisation').vm.$emit('update:modelValue', ' GGD ')
		control(wrapper, 'advice-request-subject').vm.$emit('update:modelValue', ' Geluid ')
		control(wrapper, 'advice-request-deadline').vm.$emit('update:modelValue', new Date(2026, 0, 9))
		control(wrapper, 'advice-request-question').vm.$emit('update:modelValue', ' Hoe hard? ')
		await flushPromises()

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(mockPost).toHaveBeenCalledTimes(1)
		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/cases/case-1/advice-requests', {
			kind: 'external',
			advisor: 'GGD',
			subject: 'Geluid',
			deadline: '2026-01-09',
			question: 'Hoe hard?',
		})
		expect(wrapper.emitted('requested')).toEqual([[{ id: 'adv-7' }]])
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('searches advisers from the picker and posts the chosen one from the confirm button', async () => {
		mockPost.mockResolvedValue({ data: { id: 'adv-8' } })
		const wrapper = await mountDialog({ caseId: 'case-1' })
		const picker = control(wrapper, 'advice-request-adviser')
		expect(picker.props('filterBy')()).toBe(true)

		mockGet.mockResolvedValueOnce({ data: { ocs: { data: { users: [{ label: 'Lars', value: { shareType: 0, shareWith: 'lars' } }] } } } })
		picker.vm.$emit('search', 'La')
		await flushPromises()

		expect(mockGet).toHaveBeenLastCalledWith('/ocs/v2.php/apps/files_sharing/api/v1/sharees', expect.objectContaining({ params: expect.objectContaining({ search: 'La' }) }))
		expect(picker.props('options')).toEqual([{ id: 'lars', label: 'Lars' }])
		expect(picker.props('loading')).toBe(false)

		picker.vm.$emit('update:modelValue', { id: 'lars', label: 'Lars' })
		await flushPromises()
		await wrapper.find('[data-testid="advice-request-confirm"]').trigger('click')
		await flushPromises()

		expect(mockPost.mock.calls[0][1]).toMatchObject({ kind: 'internal', advisor: 'lars' })
		expect(mockEmit).toHaveBeenCalledWith('dossiq:advice:requested', { caseId: 'case-1', advice: { id: 'adv-8' } })
	})

	it('posts nothing when submitted without an adviser', async () => {
		const wrapper = await mountDialog({ caseId: 'case-1' })
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(mockPost).not.toHaveBeenCalled()
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('falls back to its own sentence on a refusal without one', async () => {
		mockPost.mockRejectedValue(new Error('network'))
		const wrapper = await mountDialog({ caseId: 'case-1', kind: 'external' })
		control(wrapper, 'advice-request-organisation').vm.$emit('update:modelValue', 'Brandweer')
		await flushPromises()
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.find('[data-testid="dialog-notice"]').text()).toContain('The advice request could not be saved.')
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('closes from Cancel and from the dialog itself', async () => {
		const wrapper = await mountDialog({ caseId: 'case-1' })
		await wrapper.find('[data-testid="x"]').trigger('click')
		await wrapper.findAll('button').find((b) => b.text() === 'Cancel').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(mockPost).not.toHaveBeenCalled()
	})
})
