// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The personal "link my mail to cases" section says what the matcher did, in
 * words, and never lets a user switch it on without choosing an account.
 *
 * The status the server records is a refusal CODE (`account_not_owned`,
 * `register_unconfigured`, ...), because the matcher stores counts and codes and
 * never message content. A section that printed the code, or printed the
 * generic "last check" line over a refusal, would tell a user their mail was
 * being linked while nothing was. So the assertions that carry this spec are the
 * ones on the status line.
 *
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
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
		props: { modelValue: { type: Object, default: null }, options: { type: Array, default: () => [] }, disabled: Boolean },
		render() {
			return h('select', { class: 'NcSelect', disabled: this.disabled || undefined })
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
		status: { lastRunAt: '2026-09-11T06:00:00+00:00', linked: 2, scanned: 5, error: null },
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

describe('CaseEmailMatchSettings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('reads the settings from the caller\'s own endpoint', async () => {
		await open(body())

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/dossiq/api/settings/email-case-matching')
	})

	it('reports what the last check did', async () => {
		const wrapper = await open(body())

		const status = wrapper.find('[data-testid="case-email-match-status"]').text()
		expect(status).toContain('Messages read: 5')
		expect(status).toContain('Links made: 2')
	})

	it('reports a refused check in words, not as a count', async () => {
		const wrapper = await open(body({
			status: { lastRunAt: '2026-09-11T06:00:00+00:00', linked: 0, scanned: 0, error: 'account_not_owned' },
		}))

		const status = wrapper.find('[data-testid="case-email-match-status"]').text()
		expect(status).toBe('The chosen account is not yours, so nothing was read.')
	})

	it('says a first check starts at the newest mail', async () => {
		const wrapper = await open(body({ status: { lastRunAt: null, linked: 0, scanned: 0, error: null } }))

		expect(wrapper.find('[data-testid="case-email-match-status"]').text()).toContain('older mail is not linked')
	})

	it('says so when the administrator has not switched matching on', async () => {
		const wrapper = await open(body({ instanceEnabled: false }))

		expect(wrapper.find('[data-testid="case-email-match-instance-off"]').exists()).toBe(true)
	})

	it('cannot be switched on before an account is chosen', async () => {
		const wrapper = await open(body({ enabled: false, account: 0 }))

		expect(wrapper.find('.NcCheckboxRadioSwitch').attributes('disabled')).toBeDefined()
	})

	it('saves the choice to the caller\'s own endpoint', async () => {
		const wrapper = await open(body())
		axios.put.mockResolvedValue({ data: body() })

		await wrapper.find('[data-testid="case-email-match-save"]').trigger('click')
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith(
			'/index.php/apps/dossiq/api/settings/email-case-matching',
			{ enabled: true, account: 7 },
		)
	})

	it('says an account that is not yours was refused', async () => {
		const wrapper = await open(body())
		axios.put.mockRejectedValue({ response: { status: 403 } })

		await wrapper.find('[data-testid="case-email-match-save"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="case-email-match-feedback"]').text()).toBe('That mail account is not yours.')
	})
})
