// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The one bulk dialog in its four modes, now that the act is a job.
 *
 * TWO ASSERTIONS CARRY THE REQUIREMENTS, and everything else here supports
 * them.
 *
 * The first is that the button stays DISABLED while the reason is empty, in
 * every mode. Suspending, resuming and extending are statutory acts (Awb 4:5
 * and 4:14) somebody accounts for later, and doing twenty at once is exactly
 * when the justification goes unwritten. The action declares the requirement
 * and the server enforces it; the disabled button is the half that says so
 * before the click, and only a mounted template can show the two halves agree.
 *
 * The second is that the first button REHEARSES and does not write. The whole
 * point of the change is that a handler reads the skip list before four
 * hundred cases move, so a dialog whose first button committed would satisfy
 * every other assertion here and still be the old dialog.
 *
 * A FULL mount, not a shallow one: `canRehearse` is read from the template's
 * `:disabled` binding, and a shallow mount that never evaluates the template
 * cannot see a computed the template no longer reads.
 *
 * The `@nextcloud/vue` components are stubbed for the reason
 * `dialogTemplateBindings.spec.js` gives at length: several chunks deep they
 * pull in the rich-text/reference-picker stack, which assumes a live
 * Nextcloud runtime. The stubs still render slots and still emit, so the
 * bindings under test are exercised rather than skipped.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

// See bulkProgress.spec.js: jsdom environment setup dominates this file's wall
// clock, and under load it can spend the default 5s budget on its own.
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

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (match, key) =>
			vars && key in vars ? String(vars[key]) : match,
		),
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))

vi.mock('@nextcloud/vue/components/NcDialog', () => ({ default: box('NcDialog') }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({
	default: box('NcLoadingIcon'),
}))
// NOT `box()`: that stub sets `inheritAttrs: false`, which swallows the
// `data-testid` the refusal is found by, and the failure then reads as "the
// refusal is missing" rather than "the stub ate the id".
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({
	default: {
		name: 'NcNoteCard',
		render() {
			return h('div', { class: 'NcNoteCard' }, this.$slots.default?.())
		},
	},
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

const previewBulkJob = vi.fn()
const commitBulkJob = vi.fn()

vi.mock('../../src/services/bulkJobApi.js', () => ({
	ACTION_LIFECYCLE: 'dossiq:lifecycle-cases',
	ACTION_REASSIGN: 'dossiq:reassign-cases',
	ACTION_SET_ATTRIBUTE: 'dossiq:set-case-attribute',
	ACTION_TRANSITION: 'dossiq:transition-cases',
	ACTIVE_STATES: ['previewed', 'running', 'cancelling'],
	FINAL_STATES: ['completed', 'failed', 'cancelled'],
	bulkJobReportUrl: (id) => `/download/${id}`,
	cancelBulkJob: vi.fn(),
	commitBulkJob: (...args) => commitBulkJob(...args),
	fetchBulkJob: vi.fn(),
	fetchBulkJobMembers: vi.fn().mockResolvedValue({ results: [], total: 0 }),
	isFinished: (job) =>
		['completed', 'failed', 'cancelled'].includes(String(job?.state)),
	previewBulkJob: (...args) => previewBulkJob(...args),
	readRefusal: (error) => {
		const body = (error && error.response && error.response.data) || {}

		return {
			reason: String(body.reason || ''),
			message: String(body.error || ''),
			details: body.details || {},
		}
	},
	retryBulkJob: vi.fn(),
}))

const axios = (await import('@nextcloud/axios')).default
const BulkTransitionDialog = (
	await import('../../src/dialogs/BulkTransitionDialog.vue')
).default

/** A rehearsed job over two cases. */
const PREVIEWED = {
	id: 4,
	state: 'previewed',
	total: 2,
	processed: 0,
	counts: { applied: 2, skipped: 0, refused: 0, failed: 0 },
}

/**
 * Let pending promises and the render queue settle.
 *
 * @return {Promise<void>} Resolves once the queue is empty.
 */
async function flush() {
	await new Promise((resolve) => setTimeout(resolve, 0))
	await new Promise((resolve) => setTimeout(resolve, 0))
}

/**
 * Mount the dialog and let its mounted hook settle.
 *
 * @param {string} mode The dialog mode.
 * @param {object} [props] Extra props.
 * @return {Promise<object>} The wrapper.
 */
async function open(mode, props = {}) {
	const wrapper = mount(BulkTransitionDialog, {
		props: { caseIds: ['case-1', 'case-2'], mode, ...props },
	})
	await flush()

	return wrapper
}

/** The button that rehearses the act. */
const rehearse = (wrapper) => wrapper.find('[data-testid="bulk-rehearse"]')

/**
 * Type a reason into the dialog.
 *
 * @param {object} wrapper The mounted wrapper.
 * @param {string} text The reason.
 * @return {Promise<void>} Resolves when the field has the value.
 */
async function typeReason(wrapper, text) {
	await wrapper.find('[data-testid="bulk-reason"]').setValue(text)
}

beforeEach(() => {
	vi.clearAllMocks()
	previewBulkJob.mockResolvedValue(PREVIEWED)
	axios.get = vi.fn().mockResolvedValue({
		data: { transitions: [{ id: 'to-decided', label: 'Decide' }] },
	})
})

describe('BulkTransitionDialog, the three lifecycle modes', () => {
	for (const mode of ['suspend', 'resume', 'extend']) {
		it(`${mode} keeps the act unavailable while the reason is empty`, async () => {
			const wrapper = await open(mode)

			expect(rehearse(wrapper).attributes('disabled')).toBeDefined()

			await typeReason(wrapper, 'Awaiting documents')
			if (mode === 'extend') {
				await wrapper
					.find('[data-testid="bulk-new-deadline"]')
					.setValue('2026-12-01')
			}
			await flush()

			expect(rehearse(wrapper).attributes('disabled')).toBeUndefined()
		})
	}

	it('extend also waits for a new deadline, not only for the reason', async () => {
		const wrapper = await open('extend')
		await typeReason(wrapper, 'Complex case')
		await flush()

		expect(rehearse(wrapper).attributes('disabled')).toBeDefined()
	})

	it('suspend hands the gesture, the reason and the days to the job', async () => {
		const wrapper = await open('suspend')
		await typeReason(wrapper, 'Awaiting documents')
		await wrapper.find('[data-testid="bulk-days"]').setValue('21')
		await rehearse(wrapper).trigger('click')
		await flush()

		expect(previewBulkJob).toHaveBeenCalledWith({
			action: 'dossiq:lifecycle-cases',
			parameters: {
				gesture: 'suspend',
				reason: 'Awaiting documents',
				days: 21,
			},
			selection: { ids: ['case-1', 'case-2'] },
			justification: 'Awaiting documents',
		})
	})

	it('extend hands the new deadline and no days', async () => {
		const wrapper = await open('extend')
		await typeReason(wrapper, 'Complex case')
		await wrapper
			.find('[data-testid="bulk-new-deadline"]')
			.setValue('2026-12-01')
		await rehearse(wrapper).trigger('click')
		await flush()

		const { parameters } = previewBulkJob.mock.calls[0][0]
		expect(parameters).toEqual({
			gesture: 'extend',
			reason: 'Complex case',
			newEndDate: '2026-12-01',
		})
	})
})

describe('BulkTransitionDialog, the rehearsal is not the act', () => {
	it('the first button rehearses and writes nothing', async () => {
		const wrapper = await open('resume')
		await typeReason(wrapper, 'Documents arrived')
		await rehearse(wrapper).trigger('click')
		await flush()

		// The whole change is that the skip list is read BEFORE the act. A
		// dialog whose first button committed would pass every other
		// assertion in this file and still be the old dialog.
		expect(previewBulkJob).toHaveBeenCalledTimes(1)
		expect(commitBulkJob).not.toHaveBeenCalled()
	})

	it('the rehearsed job replaces the form, so the counts are what is read next', async () => {
		const wrapper = await open('resume')
		await typeReason(wrapper, 'Documents arrived')
		await rehearse(wrapper).trigger('click')
		await flush()

		expect(wrapper.find('[data-testid="bulk-job-progress"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="bulk-rehearse"]').exists()).toBe(false)
	})

	it('a version refusal names both versions rather than saying it was refused', async () => {
		previewBulkJob.mockRejectedValue({
			response: {
				data: {
					reason: 'case-type-versions',
					error: 'refused',
					details: {
						caseType: 'Bezwaar',
						versions: [2, 3],
						counts: { 2: 18, 3: 4 },
					},
				},
			},
		})

		const wrapper = await open('resume')
		await typeReason(wrapper, 'Documents arrived')
		await rehearse(wrapper).trigger('click')
		await flush()

		const refusal = wrapper.get('[data-testid="bulk-refusal"]').text()
		expect(refusal).toContain('2 and 3')
		expect(refusal).toContain('Bezwaar')
	})

	it('a ceiling refusal says the ceiling and what was selected', async () => {
		previewBulkJob.mockRejectedValue({
			response: {
				data: {
					reason: 'ceiling',
					error: 'refused',
					details: { ceiling: 500, count: 900 },
				},
			},
		})

		const wrapper = await open('resume')
		await typeReason(wrapper, 'Documents arrived')
		await rehearse(wrapper).trigger('click')
		await flush()

		const refusal = wrapper.get('[data-testid="bulk-refusal"]').text()
		expect(refusal).toContain('500')
		expect(refusal).toContain('900')
	})
})

describe('BulkTransitionDialog, the transition mode', () => {
	it('still asks the first case for its available transitions', async () => {
		await open('transition')

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(String(axios.get.mock.calls[0][0])).toContain('case-1')
	})

	it('needs a transition as well as a reason', async () => {
		const wrapper = await open('transition')
		await typeReason(wrapper, 'Handled in bulk')
		await flush()

		// The reason alone is not enough: without a transition the act has no
		// target, and the server would refuse it after the click.
		expect(rehearse(wrapper).attributes('disabled')).toBeDefined()
	})

	it('hands the transition and the reason as the comment on each case', async () => {
		const wrapper = await open('transition')
		wrapper.vm.selectedTransition = { id: 'to-decided', label: 'Decide' }
		await typeReason(wrapper, 'Handled in bulk')
		await flush()

		await rehearse(wrapper).trigger('click')
		await flush()

		expect(previewBulkJob).toHaveBeenCalledWith({
			action: 'dossiq:transition-cases',
			parameters: { transitionId: 'to-decided', comment: 'Handled in bulk' },
			selection: { ids: ['case-1', 'case-2'] },
			justification: 'Handled in bulk',
		})
	})

	it('a selection widened to the whole result sends the search, not the ids', async () => {
		const wrapper = await open('transition', {
			matchingTotal: 400,
			filters: { caseType: 'bezwaar' },
		})
		wrapper.vm.selectedTransition = { id: 'to-decided', label: 'Decide' }
		await typeReason(wrapper, 'Handled in bulk')
		await flush()

		await wrapper.get('[data-testid="bulk-selection-widen"]').trigger('click')
		await flush()
		await rehearse(wrapper).trigger('click')
		await flush()

		expect(previewBulkJob.mock.calls[0][0].selection).toEqual({
			query: { caseType: 'bezwaar' },
		})
	})
})
