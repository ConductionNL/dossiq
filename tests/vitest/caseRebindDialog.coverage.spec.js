// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CaseRebindDialog, the paths `caseRebindImpact.spec.js` leaves out: closing
 * through the dialog and Cancel; the pickers handing over only the id of the
 * chosen case type and status; clearing the target; moving a removed answer
 * asking the server again after the debounce, and that debounce dying with
 * the dialog; the server's refusal shown verbatim (or the fallback) when the
 * options, the preview or the rebind itself fail; and a confirm that is
 * refused locally before anything is posted.
 *
 * The `@nextcloud/vue` components are stubbed the same way as in
 * `caseRebindImpact.spec.js`, except that the select stub renders one button
 * per option and runs the dialog's `reduce`, so the value the dialog stores
 * is what the real picker would hand it.
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.setConfig({ testTimeout: 30000, hookTimeout: 30000 })

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (match, key) =>
			vars && key in vars ? String(vars[key]) : match,
		),
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

vi.mock('@conduction/nextcloud-vue', async (importOriginal) => ({
	...(await importOriginal()),
	CnDialog: {
		name: 'CnDialog',
		inheritAttrs: false,
		emits: ['closing'],
		render() {
			return h('div', { class: 'CnDialog' }, [
				h('button', {
					'data-testid': 'stub-dialog-closing',
					onClick: () => this.$emit('closing'),
				}),
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	},
}))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: {
		name: 'NcSelect',
		props: {
			modelValue: { type: null, default: null },
			options: { type: Array, default: () => [] },
			reduce: { type: Function, default: null },
		},
		emits: ['update:modelValue'],
		render() {
			return h('div', { class: 'NcSelect' }, [
				...this.options.map((option, index) =>
					h('button', {
						class: 'stub-option',
						'data-index': index,
						onClick: () =>
							this.$emit(
								'update:modelValue',
								this.reduce ? this.reduce(option) : option,
							),
					}),
				),
				h('button', {
					class: 'stub-clear',
					onClick: () => this.$emit('update:modelValue', null),
				}),
			])
		},
	},
}))
vi.mock('@nextcloud/vue/components/NcTextArea', () => ({
	default: {
		name: 'NcTextArea',
		props: { modelValue: { type: String, default: '' } },
		emits: ['update:modelValue'],
		render() {
			return h('textarea', {
				value: this.modelValue,
				onInput: (event) =>
					this.$emit('update:modelValue', event.target.value),
			})
		},
	},
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		props: { disabled: { type: Boolean, default: false } },
		emits: ['click'],
		render() {
			return h(
				'button',
				{
					class: 'NcButton',
					disabled: this.disabled,
					onClick: (event) => this.$emit('click', event),
				},
				this.$slots.default?.(),
			)
		},
	},
}))

const axios = (await import('@nextcloud/axios')).default
const { emit } = await import('@nextcloud/event-bus')
const CaseRebindDialog = (await import('../../src/dialogs/CaseRebindDialog.vue'))
	.default

const ENDPOINT = '/apps/dossiq/api/case/case-1/rebind'

/**
 * A preview with nothing removed unless asked, so confirm only waits on the
 * status and the reason.
 *
 * @param {object} [overrides] Fields to replace.
 * @return {object} The preview.
 */
function preview(overrides = {}) {
	return {
		to: { caseType: 'ct-omg', title: 'Omgevingsvergunning' },
		statuses: [
			{ id: 'omg-behandeling', name: 'In behandeling' },
			{ id: 'omg-toetsing', name: 'Toetsing' },
		],
		results: { carried: true, note: '' },
		run: { moved: false, reason: 'The run stays where it is.' },
		impact: { dropped: [], ported: [], required: [], complete: true },
		canRebind: true,
		...overrides,
	}
}

/**
 * Let pending promises, the debounce and the render queue settle.
 *
 * @param {number} ms How long to wait beyond the queue.
 * @return {Promise<void>} Resolves once settled.
 */
async function flush(ms = 0) {
	await new Promise((resolve) => setTimeout(resolve, ms))
	await new Promise((resolve) => setTimeout(resolve, 0))
	await new Promise((resolve) => setTimeout(resolve, 0))
}

/**
 * Click the option at `index` of the picker with this test id.
 *
 * @param {object} wrapper The mounted dialog.
 * @param {string} testid The picker's data-testid.
 * @param {number} index The option.
 */
async function pick(wrapper, testid, index) {
	await wrapper
		.find(`[data-testid="${testid}"]`)
		.findAll('.stub-option')[index]
		.trigger('click')
}

/**
 * The options answer the dialog loads on mount.
 *
 * @return {object} The response.
 */
function optionsAnswer() {
	return {
		data: {
			current: { title: 'Kapvergunning', status: '' },
			targets: [
				{ id: 'ct-kap-2', title: 'Kapvergunning', version: '2', sameChain: true },
				{ id: 'ct-omg', title: 'Omgevingsvergunning' },
			],
		},
	}
}

describe('CaseRebindDialog — remaining paths', () => {
	beforeEach(() => {
		emit.mockReset()
		axios.get = vi.fn((url, config) =>
			Promise.resolve(
				config?.params?.target
					? { data: { preview: preview() } }
					: optionsAnswer(),
			),
		)
		axios.post = vi.fn().mockResolvedValue({ data: { rebound: true } })
	})

	it('emits close from the dialog and from Cancel', async () => {
		const wrapper = mount(CaseRebindDialog, { props: { caseId: 'case-1' } })
		await flush()

		expect(wrapper.text()).toContain(
			'This case runs as Kapvergunning, in no status.',
		)
		await wrapper.find('[data-testid="stub-dialog-closing"]').trigger('click')
		await wrapper.find('[data-testid="case-rebind-cancel"]').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(2)
	})

	it('asks the preview with the ids the pickers hand over, and clears it with the target', async () => {
		const wrapper = mount(CaseRebindDialog, { props: { caseId: 'case-1' } })
		await flush()

		await pick(wrapper, 'case-rebind-target', 1)
		await flush()
		expect(axios.get).toHaveBeenLastCalledWith(ENDPOINT, {
			params: { target: 'ct-omg', status: '', remap: {}, properties: {} },
		})

		await pick(wrapper, 'case-rebind-status', 1)
		await flush()
		expect(axios.get).toHaveBeenLastCalledWith(ENDPOINT, {
			params: {
				target: 'ct-omg',
				status: 'omg-toetsing',
				remap: {},
				properties: {},
			},
		})
		expect(wrapper.find('[data-testid="case-rebind-preview"]').text()).toContain(
			'The run stays where it is.',
		)

		const calls = axios.get.mock.calls.length
		await wrapper
			.find('[data-testid="case-rebind-target"] .stub-clear')
			.trigger('click')
		await flush()
		expect(axios.get).toHaveBeenCalledTimes(calls)
		expect(wrapper.find('[data-testid="case-rebind-preview"]').exists()).toBe(
			false,
		)
		expect(wrapper.find('[data-testid="case-rebind-status"]').exists()).toBe(
			false,
		)
	})

	it('asks again with the moved answer once the debounce settles', async () => {
		axios.get = vi.fn((url, config) =>
			Promise.resolve(
				config?.params?.target
					? {
							data: {
								preview: preview({
									impact: {
										dropped: [
											{
												name: 'boomsoort',
												kind: 'text',
												value: 'eik',
												reason: 'absent',
												candidates: ['soort'],
											},
										],
										ported: [],
										required: [],
										complete: true,
									},
								}),
							},
						}
					: optionsAnswer(),
			),
		)
		const wrapper = mount(CaseRebindDialog, { props: { caseId: 'case-1' } })
		await flush()
		await pick(wrapper, 'case-rebind-target', 1)
		await flush()

		await pick(wrapper, 'case-rebind-move-boomsoort', 0)
		await flush(400)

		expect(axios.get).toHaveBeenLastCalledWith(ENDPOINT, {
			params: {
				target: 'ct-omg',
				status: '',
				remap: { boomsoort: 'soort' },
				properties: {},
			},
		})
	})

	it('drops a pending preview when the dialog closes', async () => {
		const wrapper = mount(CaseRebindDialog, { props: { caseId: 'case-1' } })
		await flush()
		await pick(wrapper, 'case-rebind-target', 1)
		await flush()
		const calls = axios.get.mock.calls.length

		wrapper.vm.schedulePreview()
		wrapper.unmount()
		await flush(400)

		expect(axios.get).toHaveBeenCalledTimes(calls)
	})

	it('shows the server sentence when the options cannot be read, and the empty line', async () => {
		axios.get = vi.fn().mockRejectedValue({
			response: { data: { message: 'You may not change this case.' } },
		})
		const wrapper = mount(CaseRebindDialog, { props: { caseId: 'case-1' } })
		await flush()

		expect(wrapper.find('[data-testid="case-rebind-error"]').text()).toBe(
			'You may not change this case.',
		)
		expect(wrapper.find('[data-testid="case-rebind-empty"]').exists()).toBe(
			true,
		)
	})

	it('shows the fallback when the preview fails without a sentence', async () => {
		const wrapper = mount(CaseRebindDialog, { props: { caseId: 'case-1' } })
		await flush()
		axios.get = vi.fn().mockRejectedValue(new Error('network'))

		await pick(wrapper, 'case-rebind-target', 0)
		await flush()

		expect(wrapper.find('[data-testid="case-rebind-error"]').text()).toBe(
			'The case was not rebound.',
		)
		expect(wrapper.find('[data-testid="case-rebind-preview"]').exists()).toBe(
			false,
		)
	})

	it('posts nothing when confirm is not allowed yet', async () => {
		const wrapper = mount(CaseRebindDialog, { props: { caseId: 'case-1' } })
		await flush()

		await wrapper.vm.confirm()

		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('keeps the dialog open with the refusal when the rebind is refused', async () => {
		axios.post = vi.fn().mockRejectedValue({
			response: {
				data: { message: 'That case type requires bouwjaar in that status.' },
			},
		})
		const wrapper = mount(CaseRebindDialog, { props: { caseId: 'case-1' } })
		await flush()
		await pick(wrapper, 'case-rebind-target', 1)
		await flush()
		await pick(wrapper, 'case-rebind-status', 0)
		await flush()
		await wrapper
			.find('[data-testid="case-rebind-reason"]')
			.setValue('  Verkeerd ingeboekt ')

		await wrapper.find('[data-testid="case-rebind-confirm"]').trigger('click')
		await flush()

		expect(axios.post).toHaveBeenCalledWith(ENDPOINT, {
			target: 'ct-omg',
			status: 'omg-behandeling',
			reason: 'Verkeerd ingeboekt',
			properties: {},
			remap: {},
			confirmDropped: [],
		})
		expect(wrapper.find('[data-testid="case-rebind-error"]').text()).toBe(
			'That case type requires bouwjaar in that status.',
		)
		expect(emit).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(
			wrapper.find('[data-testid="case-rebind-confirm"]').attributes('disabled'),
		).toBeUndefined()
	})
})
