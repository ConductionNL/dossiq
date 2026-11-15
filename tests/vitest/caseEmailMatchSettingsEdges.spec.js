// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The edges of the personal mail-to-case section: a settings read that fails,
 * the account picker writing the chosen id, the save that succeeds, and the
 * words a refused or failed save shows.
 *
 * @spec openspec/specs/email-case-matching/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

/**
 * A stub that renders its default slot inside a tag.
 *
 * @param {string} name The component name.
 * @param {string} tag  The element to render.
 * @return {object} The stub component.
 */
function boxStub(name, tag = 'div') {
	return {
		name,
		props: { type: { type: String, default: '' }, disabled: Boolean },
		emits: ['click'],
		render() {
			return h(
				tag,
				{
					class: name,
					'data-type': this.type,
					disabled: this.disabled || undefined,
					onClick: () => this.$emit('click'),
				},
				this.$slots.default ? this.$slots.default() : [],
			)
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: boxStub('NcButton', 'button'),
	NcLoadingIcon: boxStub('NcLoadingIcon', 'span'),
	NcNoteCard: boxStub('NcNoteCard'),
	NcSelect: {
		name: 'NcSelect',
		props: {
			modelValue: { type: Object, default: null },
			options: { type: Array, default: () => [] },
			disabled: Boolean,
		},
		emits: ['update:modelValue'],
		render() {
			return h('select', {
				class: 'NcSelect',
				disabled: this.disabled || undefined,
			})
		},
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: { modelValue: Boolean, disabled: Boolean },
		emits: ['update:modelValue'],
		render() {
			return h('input', {
				type: 'checkbox',
				class: 'NcCheckboxRadioSwitch',
				checked: this.modelValue,
				disabled: this.disabled || undefined,
			})
		},
	},
}))

const CaseEmailMatchSettings = (
	await import('../../src/views/settings/CaseEmailMatchSettings.vue')
).default

/**
 * A settings payload as the endpoint answers it.
 *
 * @param {object} overrides Values to override.
 * @return {object} The body.
 */
function body(overrides = {}) {
	return {
		instanceEnabled: true,
		enabled: true,
		account: 7,
		accounts: [{ id: 7, name: 'Werk', email: 'alice@gemeente.test' }],
		status: {
			lastRunAt: '2026-09-11T06:00:00+00:00',
			linked: 2,
			scanned: 5,
			error: null,
		},
		...overrides,
	}
}

/**
 * Mount the section against a settings response and let it settle.
 *
 * @param {object} response The body the GET answers.
 * @return {Promise<object>} The wrapper.
 */
async function open(response) {
	axios.get.mockResolvedValue({ data: response })
	const wrapper = mount(CaseEmailMatchSettings)
	await flushPromises()
	return wrapper
}

const URL = '/index.php/apps/dossiq/api/settings/email-case-matching'

/**
 * Click save and let it settle.
 *
 * @param {object} wrapper The mounted section.
 * @return {Promise<void>}
 */
async function clickSave(wrapper) {
	await wrapper.find('[data-testid="case-email-match-save"]').trigger('click')
	await flushPromises()
}

describe('CaseEmailMatchSettings edges', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('says so when the settings cannot be read, and stops loading', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const wrapper = mount(CaseEmailMatchSettings)
		await flushPromises()

		expect(wrapper.find('.NcLoadingIcon').exists()).toBe(false)
		const feedback = wrapper.find('[data-testid="case-email-match-feedback"]')
		expect(feedback.attributes('data-type')).toBe('error')
		expect(feedback.text()).toBe('Could not load your settings. Reload the page to try again.')
	})

	it('warns when there is no mail account to choose', async () => {
		const wrapper = await open(body({ accounts: [], account: 0 }))

		expect(wrapper.find('[data-testid="case-email-match-no-accounts"]').text()).toBe(
			'Add a mail account in the Mail app first.',
		)
		expect(wrapper.find('.NcSelect').attributes('disabled')).toBeDefined()
	})

	it('labels each account with its address, or its name alone', async () => {
		const wrapper = await open(
			body({ accounts: [{ id: 7, name: 'Werk', email: 'alice@gemeente.test' }, { id: 8, name: 'Privé', email: '' }] }),
		)

		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual([
			{ id: 7, label: 'Werk (alice@gemeente.test)' },
			{ id: 8, label: 'Privé' },
		])
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue')).toEqual({
			id: 7,
			label: 'Werk (alice@gemeente.test)',
		})
	})

	it('saves the account picked in the select, and none when it is cleared', async () => {
		const wrapper = await open(
			body({ accounts: [{ id: 7, name: 'Werk', email: '' }, { id: 9, name: 'Team', email: '' }] }),
		)
		axios.put.mockResolvedValue({ data: body() })
		const select = wrapper.findComponent({ name: 'NcSelect' })

		select.vm.$emit('update:modelValue', { id: 9, label: 'Team' })
		await clickSave(wrapper)
		expect(axios.put).toHaveBeenLastCalledWith(URL, { enabled: true, account: 9 })

		select.vm.$emit('update:modelValue', null)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.NcCheckboxRadioSwitch').attributes('disabled')).toBeDefined()
		await clickSave(wrapper)
		expect(axios.put).toHaveBeenLastCalledWith(URL, { enabled: true, account: 0 })
	})

	it('confirms a save and applies what the server answered', async () => {
		const wrapper = await open(body())
		axios.put.mockResolvedValue({ data: body({ instanceEnabled: false }) })

		await clickSave(wrapper)

		const feedback = wrapper.find('[data-testid="case-email-match-feedback"]')
		expect(feedback.attributes('data-type')).toBe('success')
		expect(feedback.text()).toBe('Saved. New mail is checked every five minutes.')
		expect(wrapper.find('[data-testid="case-email-match-instance-off"]').exists()).toBe(true)
	})

	it('asks for an account when the save is refused as incomplete', async () => {
		const wrapper = await open(body())
		axios.put.mockRejectedValue({ response: { status: 400 } })

		await clickSave(wrapper)

		expect(wrapper.find('[data-testid="case-email-match-feedback"]').text()).toBe('Choose a mail account first.')
	})

	it('offers a retry when the save fails otherwise', async () => {
		const wrapper = await open(body())
		axios.put.mockRejectedValue(new Error('network'))

		await clickSave(wrapper)

		const feedback = wrapper.find('[data-testid="case-email-match-feedback"]')
		expect(feedback.attributes('data-type')).toBe('error')
		expect(feedback.text()).toBe('Could not save. Try again.')
		expect(wrapper.find('[data-testid="case-email-match-save"]').attributes('disabled')).toBeUndefined()
	})
})
