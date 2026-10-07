// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type change dialog shows what happens to the answers on the case,
 * and will not confirm until that picture is complete.
 *
 * TWO ASSERTIONS CARRY THE REQUIREMENT. The first is that the three groups
 * (removed, carried over, needed now) render the server's impact, value and
 * all, before anything is saved. The second is that the confirm button stays
 * DISABLED while a required field has no valid answer, and while the removed
 * values are not confirmed; and that the POST names exactly the removed
 * answers the coordinator saw, so the server can refuse a case that changed.
 *
 * A full mount with the `@nextcloud/vue` components stubbed, for the reason
 * `bulkTransitionDialog.spec.js` gives: the real ones pull in a runtime this
 * environment does not have. The stubs still render slots and emit, so the
 * bindings under test run.
 *
 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.setConfig({ testTimeout: 30000, hookTimeout: 30000 })

/**
 * A stub that renders its default and actions slots.
 *
 * @param {string} name The component name.
 * @return {object} The stub component.
 */
function box(name) {
	return {
		name,
		inheritAttrs: false,
		render() {
			return h('div', { class: name }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	}
}

/**
 * A stub input carrying its v-model value.
 *
 * @param {string} name The component name.
 * @return {object} The stub component.
 */
function field(name) {
	return {
		name,
		props: {
			modelValue: { type: [String, Number, Object], default: '' },
		},
		emits: ['update:modelValue'],
		render() {
			return h('input', {
				class: name,
				value: String(this.modelValue ?? ''),
				onInput: (event) =>
					this.$emit('update:modelValue', event.target.value),
			})
		},
	}
}

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (match, key) =>
			vars && key in vars ? String(vars[key]) : match,
		),
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

vi.mock('@nextcloud/vue/components/NcDialog', () => ({ default: box('NcDialog') }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: field('NcSelect'),
}))
vi.mock('@nextcloud/vue/components/NcTextArea', () => ({
	default: field('NcTextArea'),
}))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({
	default: field('NcTextField'),
}))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({
	default: {
		name: 'NcCheckboxRadioSwitch',
		props: { modelValue: { type: Boolean, default: false } },
		emits: ['update:modelValue'],
		render() {
			return h('label', { class: 'NcCheckboxRadioSwitch' }, [
				h('input', {
					type: 'checkbox',
					checked: this.modelValue,
					onChange: (event) =>
						this.$emit('update:modelValue', event.target.checked),
				}),
				this.$slots.default?.(),
			])
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
const CaseRebindDialog = (await import('../../src/dialogs/CaseRebindDialog.vue'))
	.default
const CaseRebindImpact = (
	await import('../../src/components/case/CaseRebindImpact.vue')
).default

/**
 * What the server answers for one preview question.
 *
 * `bouwjaar` is required at Toetsing and valid only as a whole number, the
 * way {@see RebindValueConverter} judges it.
 *
 * @param {object} params The query the dialog sent.
 * @return {object} The preview.
 */
function previewFor(params) {
	const status = params.status || ''
	const answer = String(params.properties?.bouwjaar ?? '')
	const valid = /^\d+$/.test(answer)
	const required =
		status === 'omg-toetsing'
			? [
					{
						name: 'bouwjaar',
						kind: 'integer',
						choices: [],
						description: '',
						value: answer,
						valid,
					},
				]
			: []

	return {
		to: { caseType: 'ct-omg', title: 'Omgevingsvergunning' },
		statuses: [
			{ id: 'omg-behandeling', name: 'In behandeling' },
			{ id: 'omg-toetsing', name: 'Toetsing' },
		],
		results: { carried: true, note: '' },
		run: { moved: false, reason: 'The run stays where it is.' },
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
			ported: [
				{
					source: 'oppervlakte',
					target: 'oppervlakte',
					sourceKind: 'integer',
					targetKind: 'number',
					value: '120',
					newValue: '120',
					mapping: 'converted',
				},
			],
			required,
			complete: required.every((row) => row.valid),
		},
		canRebind: status !== '' && required.every((row) => row.valid),
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
 * Type into the stub field with this test id.
 *
 * @param {object} wrapper The mounted dialog.
 * @param {string} testid The data-testid.
 * @param {string} value The text.
 */
async function type(wrapper, testid, value) {
	await wrapper.find(`[data-testid="${testid}"]`).setValue(value)
}

/**
 * The confirm button's disabled state.
 *
 * @param {object} wrapper The mounted dialog.
 * @return {boolean} True when disabled.
 */
function confirmDisabled(wrapper) {
	return (
		wrapper.find('[data-testid="case-rebind-confirm"]').attributes('disabled')
		!== undefined
	)
}

describe('CaseRebindDialog property impact', () => {
	beforeEach(() => {
		axios.get = vi.fn((url, config) => {
			if (config?.params?.target) {
				return Promise.resolve({
					data: { preview: previewFor(config.params) },
				})
			}
			return Promise.resolve({
				data: {
					current: { title: 'Kapvergunning', status: 'In behandeling' },
					targets: [{ id: 'ct-omg', title: 'Omgevingsvergunning' }],
				},
			})
		})
		axios.post = vi.fn().mockResolvedValue({ data: { rebound: true } })
	})

	it('shows the three groups, and blocks confirm until required and removed are settled', async () => {
		const wrapper = mount(CaseRebindDialog, { props: { caseId: 'case-1' } })
		await flush()

		await type(wrapper, 'case-rebind-target', 'ct-omg')
		await flush()
		await type(wrapper, 'case-rebind-status', 'omg-toetsing')
		await flush()

		const dropped = wrapper.find('[data-testid="case-rebind-dropped-boomsoort"]')
		expect(dropped.exists()).toBe(true)
		expect(dropped.text()).toContain('eik')
		expect(dropped.text()).toContain(
			'Omgevingsvergunning has no field with this name.',
		)

		const ported = wrapper.find('[data-testid="case-rebind-ported-oppervlakte"]')
		expect(ported.text()).toContain('oppervlakte → oppervlakte')
		expect(ported.text()).toContain('whole number becomes number')

		expect(
			wrapper.find('[data-testid="case-rebind-required-bouwjaar"]').exists(),
		).toBe(true)
		expect(
			wrapper
				.find('[data-testid="case-rebind-required-bouwjaar-problem"]')
				.text(),
		).toBe('Fill this in to continue.')

		await type(wrapper, 'case-rebind-reason', 'Verkeerd ingeboekt')
		await wrapper
			.find('[data-testid="case-rebind-confirm-drop"] input')
			.setValue(true)
		await flush()
		// Removed values confirmed and a reason given, but bouwjaar is empty.
		expect(confirmDisabled(wrapper)).toBe(true)

		await wrapper
			.find('[data-testid="case-rebind-required-bouwjaar"] input')
			.setValue('19.5')
		await flush(400)
		expect(
			wrapper
				.find('[data-testid="case-rebind-required-bouwjaar-problem"]')
				.text(),
		).toBe('This value does not fit this field.')
		expect(confirmDisabled(wrapper)).toBe(true)

		await wrapper
			.find('[data-testid="case-rebind-required-bouwjaar"] input')
			.setValue('1974')
		await flush(400)
		expect(confirmDisabled(wrapper)).toBe(false)

		// Taking back the confirmation of the loss blocks the button again.
		await wrapper
			.find('[data-testid="case-rebind-confirm-drop"] input')
			.setValue(false)
		expect(confirmDisabled(wrapper)).toBe(true)
		await wrapper
			.find('[data-testid="case-rebind-confirm-drop"] input')
			.setValue(true)

		await wrapper.find('[data-testid="case-rebind-confirm"]').trigger('click')
		await flush()

		expect(axios.post).toHaveBeenCalledTimes(1)
		const body = axios.post.mock.calls[0][1]
		expect(body.confirmDropped).toEqual(['boomsoort'])
		expect(body.properties).toEqual({ bouwjaar: '1974' })
		expect(body.status).toBe('omg-toetsing')
		// The preview the dialog acted on carried the answer too.
		const lastPreview = axios.get.mock.calls.at(-1)[1].params
		expect(lastPreview.properties).toEqual({ bouwjaar: '1974' })
	})
})

describe('CaseRebindImpact', () => {
	it('reports a move of a removed answer, and its undo', async () => {
		const impact = previewFor({ status: '' }).impact
		const wrapper = mount(CaseRebindImpact, {
			props: { impact, targetTitle: 'Omgevingsvergunning' },
		})

		await wrapper
			.find('[data-testid="case-rebind-move-boomsoort"]')
			.setValue('soort')
		expect(wrapper.emitted('update:remap')[0][0]).toEqual({
			boomsoort: 'soort',
		})

		await wrapper.setProps({
			remap: { boomsoort: 'soort' },
			impact: {
				...impact,
				dropped: [],
				ported: [
					...impact.ported,
					{
						source: 'boomsoort',
						target: 'soort',
						sourceKind: 'text',
						targetKind: 'text',
						value: 'eik',
						newValue: 'eik',
						mapping: 'remapped',
					},
				],
			},
		})
		expect(wrapper.text()).toContain('Nothing is removed.')
		expect(wrapper.text()).toContain('Moved by you')

		await wrapper
			.find('[data-testid="case-rebind-unmove-boomsoort"]')
			.trigger('click')
		expect(wrapper.emitted('update:remap')[1][0]).toEqual({})
	})
})
