// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The one bulk dialog in its four modes.
 *
 * The assertion that carries the requirement is Execute staying DISABLED
 * while the reason is empty, in every mode. Suspending, resuming and
 * extending are statutory acts (Awb 4:5 and 4:14) that someone has to
 * justify later, and doing twenty at once is exactly when the justification
 * goes unwritten. The server refuses a reasonless batch too, but a disabled
 * button says so before the click rather than after it, and only a mounted
 * template can show whether the two halves agree.
 *
 * A FULL mount, not a shallow one: `canExecute` is read from the template's
 * `:disabled` binding, and a shallow mount that never evaluates the template
 * cannot see a computed the template no longer reads.
 *
 * The `@nextcloud/vue` components are stubbed for the reason
 * `dialogTemplateBindings.spec.js` gives at length — several chunks deep they
 * pull in the rich-text/reference-picker stack, which assumes a live
 * Nextcloud runtime. The stubs still render slots and still emit, so the
 * bindings under test are exercised rather than skipped.
 *
 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

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
 * A stub input carrying its v-model value and disabled state.
 *
 * @param {string} name The component name.
 * @return {object} The stub component.
 */
function field(name) {
	return {
		name,
		// NOT `inheritAttrs: false`: the dialog addresses its fields by
		// `data-testid`, which is a fallthrough attribute. A stub that
		// swallows attrs makes every field unfindable and the failure reads
		// as "the field is missing" rather than "the stub ate the id".
		props: {
			modelValue: { type: [String, Number, Object], default: '' },
			disabled: { type: Boolean, default: false },
		},
		emits: ['update:modelValue'],
		render() {
			return h('input', {
				class: name,
				disabled: this.disabled,
				value: String(this.modelValue ?? ''),
				onInput: (event) =>
					this.$emit('update:modelValue', event.target.value),
			})
		},
	}
}

vi.mock('@nextcloud/vue/components/NcDialog', () => ({ default: box('NcDialog') }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({
	default: box('NcLoadingIcon'),
}))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: field('NcSelect'),
}))
vi.mock('@nextcloud/vue/components/NcTextArea', () => ({
	default: field('NcTextArea'),
}))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({
	default: field('NcTextField'),
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
const BulkTransitionDialog = (
	await import('../../src/dialogs/BulkTransitionDialog.vue')
).default

/**
 * A preview response in which every case is ready.
 *
 * @param {Array<string>} ids The case ids.
 * @return {object} An axios-shaped response.
 */
function readyPreview(ids) {
	const results = {}
	for (const id of ids) {
		results[id] = { status: 'ready', reasons: [] }
	}
	return { data: { results } }
}

/**
 * Mount the dialog and let its mounted hook settle.
 *
 * @param {string} mode The dialog mode.
 * @param {Array<string>} [caseIds] The selection.
 * @return {Promise<object>} The wrapper.
 */
async function open(mode, caseIds = ['case-1', 'case-2']) {
	const wrapper = mount(BulkTransitionDialog, { props: { caseIds, mode } })
	await flush()
	return wrapper
}

/**
 * Let pending promises and the render queue settle.
 *
 * @return {Promise<void>}
 */
async function flush() {
	await new Promise((resolve) => setTimeout(resolve, 0))
	await new Promise((resolve) => setTimeout(resolve, 0))
}

/**
 * The Execute button.
 *
 * @param {object} wrapper The mounted wrapper.
 * @return {object} The button wrapper.
 */
const execute = (wrapper) => wrapper.find('[data-testid="bulk-execute"]')

/**
 * Type a reason into the dialog.
 *
 * @param {object} wrapper The mounted wrapper.
 * @param {string} text The reason.
 * @return {Promise<void>}
 */
async function typeReason(wrapper, text) {
	await wrapper.find('[data-testid="bulk-reason"]').setValue(text)
}

describe('BulkTransitionDialog, the three lifecycle modes', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		axios.post.mockResolvedValue(readyPreview(['case-1', 'case-2']))
	})

	it.each(['suspend', 'resume', 'extend'])(
		'%s previews on open without asking for a transition',
		async (mode) => {
			const wrapper = await open(mode)

			expect(axios.get).not.toHaveBeenCalled()
			expect(axios.post).toHaveBeenCalledTimes(1)
			expect(axios.post.mock.calls[0][1]).toEqual({
				caseIds: ['case-1', 'case-2'],
				gesture: mode,
			})
			expect(wrapper.find('.NcSelect').exists()).toBe(false)
		},
	)

	it.each(['suspend', 'resume', 'extend'])(
		'%s keeps Execute disabled while the reason is empty',
		async (mode) => {
			const wrapper = await open(mode)

			expect(execute(wrapper).attributes('disabled')).toBeDefined()

			await typeReason(wrapper, 'Awaiting documents')
			await flush()

			// Extend still wants a date; the other two are ready to go.
			expect(execute(wrapper).attributes('disabled') === undefined).toBe(
				mode !== 'extend',
			)
		},
	)

	it('extend also waits for a new deadline', async () => {
		const wrapper = await open('extend')
		await typeReason(wrapper, 'Complex case')
		await flush()

		expect(execute(wrapper).attributes('disabled')).toBeDefined()

		await wrapper
			.find('[data-testid="bulk-new-deadline"]')
			.setValue('2026-12-01')
		await flush()

		expect(execute(wrapper).attributes('disabled')).toBeUndefined()
	})

	it('suspend posts the reason and the days', async () => {
		const wrapper = await open('suspend', ['case-1'])
		axios.post.mockResolvedValue(readyPreview(['case-1']))
		await typeReason(wrapper, 'Awaiting documents')
		await wrapper.find('[data-testid="bulk-days"]').setValue('21')
		await flush()

		await execute(wrapper).trigger('click')
		await flush()

		const [url, payload] = axios.post.mock.calls.at(-1)
		expect(url).toContain('bulk-transition/execute')
		expect(payload).toEqual({
			caseIds: ['case-1'],
			gesture: 'suspend',
			reason: 'Awaiting documents',
			days: 21,
		})
	})

	it('extend posts the reason and the new end date', async () => {
		const wrapper = await open('extend', ['case-1'])
		axios.post.mockResolvedValue(readyPreview(['case-1']))
		await typeReason(wrapper, 'Complex case')
		await wrapper
			.find('[data-testid="bulk-new-deadline"]')
			.setValue('2026-12-01')
		await flush()

		await execute(wrapper).trigger('click')
		await flush()

		expect(axios.post.mock.calls.at(-1)[1]).toEqual({
			caseIds: ['case-1'],
			gesture: 'extend',
			reason: 'Complex case',
			newEndDate: '2026-12-01',
		})
	})

	it('reports a partial failure per case rather than as a success', async () => {
		const wrapper = await open('suspend', ['case-1', 'case-2'])
		await typeReason(wrapper, 'Awaiting documents')
		await flush()

		axios.post.mockResolvedValue({
			data: {
				results: {
					'case-1': { status: 'succeeded' },
					'case-2': {
						status: 'failed',
						reasons: [{ message: 'already_suspended' }],
					},
				},
			},
		})

		await execute(wrapper).trigger('click')
		await flush()

		const summary = wrapper.find('[data-testid="bulk-execute-summary"]')
		expect(summary.exists()).toBe(true)
		expect(summary.text()).toContain('1 of 2')
		expect(summary.text()).toContain('case-2')
		expect(summary.text()).toContain('already_suspended')
	})
})

describe('BulkTransitionDialog, the transition mode', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		axios.get.mockResolvedValue({
			data: { transitions: [{ id: 'submit', label: 'Submit' }] },
		})
		axios.post.mockResolvedValue(readyPreview(['case-1']))
	})

	it('still asks the first case for its available transitions', async () => {
		await open('transition', ['case-1'])

		expect(axios.get.mock.calls[0][0]).toContain('available-transitions')
	})

	it('now requires a reason too, and posts it as the comment', async () => {
		const wrapper = await open('transition', ['case-1'])
		// Through the component's own v-model rather than the stub's DOM
		// input: the transition is an OBJECT, and an input element would
		// hand the dialog the string "[object Object]".
		wrapper
			.findComponent({ name: 'NcSelect' })
			.vm.$emit('update:modelValue', { id: 'submit', label: 'Submit' })
		await flush()

		// A transition used to execute with an optional comment. Reading back
		// a batch of cases that moved for no recorded reason is what that
		// allowed, so the reason gates this mode as well now.
		expect(execute(wrapper).attributes('disabled')).toBeDefined()

		await typeReason(wrapper, 'Quarterly clean-up')
		await flush()
		await execute(wrapper).trigger('click')
		await flush()

		expect(axios.post.mock.calls.at(-1)[1]).toEqual({
			caseIds: ['case-1'],
			transitionId: 'submit',
			comment: 'Quarterly clean-up',
		})
	})
})
