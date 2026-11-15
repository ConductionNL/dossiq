// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * NoticeOfDefaultDialog, the paths the main notice spec leaves out: closing
 * through the dialog, Cancel and Close; the channel and sender pickers
 * writing back what the reader chose; the case id read from the route when
 * the action hands over an unresolved token; a failed party read; the
 * sentence when an earlier notice already started the penalty; and a save
 * that is refused locally because there is no case to save on.
 *
 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import NoticeOfDefaultDialog from '../../src/dialogs/NoticeOfDefaultDialog.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

const CASE_ID = '11111111-1111-4111-8111-111111111111'

const stubs = {
	CnDialog: {
		emits: ['closing'],
		template:
			'<div><button data-testid="stub-dialog-closing" @click="$emit(\'closing\')" /><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcDateTimePicker: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template:
			'<button class="stub-date" @click="$emit(\'update:modelValue\', new Date(2026, 0, 5))" />',
	},
	NcSelect: {
		props: ['modelValue', 'options', 'reduce'],
		emits: ['update:modelValue'],
		methods: {
			pick(index) {
				const option = this.options[index]
				this.$emit(
					'update:modelValue',
					this.reduce ? this.reduce(option) : option,
				)
			},
		},
		template:
			'<div class="stub-select"><button v-for="(o, i) in options" :key="i" class="stub-option" @click="pick(i)" /></div>',
	},
}

/**
 * The axios double.
 *
 * @return {Promise<object>} The mocked axios.
 */
async function axiosDouble() {
	return (await import('@nextcloud/axios')).default
}

/**
 * Mount the dialog with the slot-rendering stubs.
 *
 * @param {object} props The dialog props.
 * @param {object} [mocks] Global mocks, e.g. `$route`.
 * @return {object} The wrapper.
 */
function mountDialog(props, mocks = {}) {
	return mount(NoticeOfDefaultDialog, {
		props,
		global: { stubs, mocks },
	})
}

describe('NoticeOfDefaultDialog — remaining paths', () => {
	beforeEach(async () => {
		const axios = await axiosDouble()
		axios.get.mockReset()
		axios.post.mockReset()
		axios.get.mockResolvedValue({
			data: { results: [{ name: 'J. de Vries' }, { name: 'B. Jansen' }] },
		})
	})

	it('emits close when the dialog closes and when Cancel is pressed', async () => {
		const wrapper = mountDialog({ caseId: CASE_ID })
		await flushPromises()

		await wrapper.find('[data-testid="stub-dialog-closing"]').trigger('click')
		await wrapper
			.find('[data-testid="notice-of-default-cancel"]')
			.trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
	})

	it('posts the date, channel and sender picked in the form, then closes from the saved state', async () => {
		const axios = await axiosDouble()
		axios.post.mockResolvedValue({
			data: { id: 'nod-2', penaltyPaymentCalculation: null },
		})
		const wrapper = mountDialog({ caseId: CASE_ID })
		await flushPromises()

		await wrapper.find('.stub-date').trigger('click')
		const selects = wrapper.findAll('.stub-select')
		// Channel: the third option is the portal; reduce keeps only its value.
		await selects[0].findAll('.stub-option')[2].trigger('click')
		// Sender: the second party.
		await selects[1].findAll('.stub-option')[1].trigger('click')
		await wrapper
			.find('[data-testid="notice-of-default-confirm"]')
			.trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/dossiq/api/notices-of-default',
			{
				caseId: CASE_ID,
				receiptDate: '2026-01-05',
				notificationChannel: 'portal',
				sender: 'B. Jansen',
			},
		)
		expect(
			wrapper.find('[data-testid="notice-of-default-saved"]').text(),
		).toBe(
			'The notice of default is on this case. An earlier notice already started the penalty.',
		)

		await wrapper.find('[data-testid="notice-of-default-close"]').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('reads the case from the route when the prop is the unresolved token', async () => {
		const axios = await axiosDouble()
		const wrapper = mountDialog(
			{ caseId: '@objectId' },
			{ $route: { params: { id: 'route-case' } } },
		)
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/dossiq/role',
			{ params: { case: 'route-case', _limit: 100 } },
		)
		expect(wrapper.findAll('.stub-select')[1].findAll('.stub-option')).toHaveLength(2)
	})

	it('reads no parties and refuses to save without a case', async () => {
		const axios = await axiosDouble()
		const wrapper = mountDialog({ caseId: '@objectId' })
		await flushPromises()

		expect(axios.get).not.toHaveBeenCalled()
		const confirm = wrapper.find('[data-testid="notice-of-default-confirm"]')
		expect(confirm.attributes('disabled')).toBeDefined()

		await wrapper.vm.save()
		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="notice-of-default-saved"]').exists()).toBe(
			false,
		)
	})

	it('leaves the sender list empty when the party read fails', async () => {
		const axios = await axiosDouble()
		axios.get.mockRejectedValue(new Error('offline'))
		const wrapper = mountDialog({ caseId: CASE_ID })
		await flushPromises()

		expect(wrapper.findAll('.stub-select')[1].findAll('.stub-option')).toHaveLength(0)
		// The form still saves with a typed name.
		axios.post.mockRejectedValue({ response: { status: 500, data: {} } })
		await wrapper
			.find('[data-testid="notice-of-default-confirm"]')
			.trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-testid="notice-of-default-error"]').text()).toBe(
			'The notice of default could not be registered.',
		)
	})
})
