// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The skip list is the feature, so the skip list is what is asserted.
 *
 * "388 of 400 succeeded" is a bar. The twelve that did not move, each with the
 * reason it refused, is the thing a handler acts on, and a count in a toast
 * that disappears is not it (D-2). So the assertions here open the list and
 * read the rows, rather than checking that a number rendered.
 *
 * SKIPPED AND REFUSED STAY APART. Collapsing them hides a permission problem
 * inside a business outcome, and a coordinator reads "12 skipped" and moves on.
 * The test that catches that is the one asserting the two counts carry
 * different words and fetch with different filters.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

// The default 5s is jsdom ENVIRONMENT setup plus the test, and on this file
// setup is about two thirds of the wall clock. Under a loaded machine, or
// beside the PHP suite, the setup alone can spend the budget and the failure
// reads as "the panel did not render" rather than "the environment was slow".
// Raised here rather than globally: the node-environment specs, which are most
// of the suite, should keep failing fast.
vi.setConfig({ testTimeout: 30000, hookTimeout: 30000 })

const fetchBulkJobMembers = vi.fn()
const commitBulkJob = vi.fn()
const cancelBulkJob = vi.fn()

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (match, key) =>
			vars && key in vars ? String(vars[key]) : match,
		),
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))

vi.mock('../../src/services/bulkJobApi.js', () => ({
	ACTIVE_STATES: ['previewed', 'running', 'cancelling'],
	FINAL_STATES: ['completed', 'failed', 'cancelled'],
	bulkJobReportUrl: (id) => `/download/${id}`,
	cancelBulkJob: (...args) => cancelBulkJob(...args),
	commitBulkJob: (...args) => commitBulkJob(...args),
	fetchBulkJob: vi.fn(),
	fetchBulkJobMembers: (...args) => fetchBulkJobMembers(...args),
	isFinished: (job) =>
		['completed', 'failed', 'cancelled'].includes(String(job?.state)),
	retryBulkJob: vi.fn(),
}))

/**
 * A stub that renders its default slot inside a named box.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function box(name) {
	return {
		name,
		inheritAttrs: false,
		render() {
			return h('div', { class: name }, this.$slots.default?.())
		},
	}
}

vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		// NOT `inheritAttrs: false`: the panel addresses its buttons by
		// `data-testid`, which is a fallthrough attribute, and a stub that
		// swallows attrs makes every button unfindable. The failure then reads
		// as "the button is missing" rather than "the stub ate the id".
		emits: ['click'],
		render() {
			return h(
				'button',
				{ onClick: () => this.$emit('click') },
				this.$slots.default?.(),
			)
		},
	},
}))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({
	default: box('NcLoadingIcon'),
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({
	default: box('NcNoteCard'),
}))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => ({
	default: {
		name: 'NcProgressBar',
		props: { value: { type: Number, default: 0 } },
		render() {
			return h('div', { 'data-value': String(this.value) })
		},
	},
}))

/** A finished job with twelve of four hundred not applied. */
const FINISHED = {
	id: 7,
	state: 'completed',
	total: 400,
	processed: 400,
	counts: { applied: 385, skipped: 12, refused: 3, failed: 0 },
}

/**
 * Mount the panel over one job.
 *
 * @param {object} job The job.
 * @return {Promise<object>} The wrapper.
 */
async function mountPanel(job) {
	const { default: BulkJobProgress } =
		await import('../../src/components/bulk/BulkJobProgress.vue')

	const wrapper = mount(BulkJobProgress, { props: { job, busy: false } })
	await flushPromises()

	return wrapper
}

beforeEach(() => {
	vi.clearAllMocks()
	fetchBulkJobMembers.mockResolvedValue({ results: [], total: 0 })
})

describe('BulkJobProgress', () => {
	it('says nothing has been written yet while the job is only rehearsed', async () => {
		const wrapper = await mountPanel({
			...FINISHED,
			state: 'previewed',
			processed: 0,
		})

		expect(wrapper.get('[data-testid="bulk-job-state"]').text()).toBe(
			'Nothing has been written yet. This is what would happen to 400 cases.',
		)
		expect(wrapper.get('[data-testid="bulk-job-commit"]').text()).toBe(
			'Apply to 400 cases',
		)
	})

	it('names the twelve that did not move, each with its reason', async () => {
		fetchBulkJobMembers.mockResolvedValue({
			results: [
				{ id: 1, objectUuid: 'case-a', reason: 'The term has not expired' },
				{ id: 2, objectUuid: 'case-b', reason: 'transition_not_available' },
			],
			total: 12,
		})

		const wrapper = await mountPanel(FINISHED)
		await wrapper.get('[data-testid="bulk-job-count-skipped"]').trigger('click')
		await flushPromises()

		const rows = wrapper.get('[data-testid="bulk-job-rows"]').text()
		expect(rows).toContain('case-a')
		expect(rows).toContain('The term has not expired')
		expect(rows).toContain('case-b')
		expect(fetchBulkJobMembers).toHaveBeenCalledWith(7, {
			outcome: 'skipped',
			limit: 25,
			offset: 0,
		})
	})

	it('a row with no reason says so rather than rendering an empty line', async () => {
		fetchBulkJobMembers.mockResolvedValue({
			results: [{ id: 1, objectUuid: 'case-a', reason: '' }],
			total: 1,
		})

		const wrapper = await mountPanel(FINISHED)
		await wrapper.get('[data-testid="bulk-job-count-skipped"]').trigger('click')
		await flushPromises()

		expect(wrapper.get('[data-testid="bulk-job-rows"]').text()).toContain(
			'No reason recorded.',
		)
	})

	it('keeps skipped and refused apart, in words and in what it fetches', async () => {
		const wrapper = await mountPanel(FINISHED)

		expect(wrapper.get('[data-testid="bulk-job-count-skipped"]').text()).toBe(
			'12 skipped',
		)
		expect(wrapper.get('[data-testid="bulk-job-count-refused"]').text()).toBe(
			'3 you may not write',
		)

		await wrapper.get('[data-testid="bulk-job-count-refused"]').trigger('click')
		await flushPromises()

		expect(fetchBulkJobMembers).toHaveBeenLastCalledWith(7, {
			outcome: 'refused',
			limit: 25,
			offset: 0,
		})
	})

	it('offers every outcome, including the ones at zero', async () => {
		// A handler looking for the skip list should find it saying "none"
		// rather than not find it and conclude nothing was skipped.
		const wrapper = await mountPanel({
			...FINISHED,
			counts: { applied: 400, skipped: 0, refused: 0, failed: 0 },
		})

		expect(wrapper.find('[data-testid="bulk-job-count-skipped"]').exists()).toBe(
			true,
		)
		expect(wrapper.get('[data-testid="bulk-job-count-skipped"]').text()).toBe(
			'0 skipped',
		)
	})

	it('offers the report and the stop button only where each makes sense', async () => {
		const running = await mountPanel({
			...FINISHED,
			state: 'running',
			processed: 120,
		})

		expect(running.get('[data-testid="bulk-job-state"]').text()).toBe(
			'Running. 120 of 400 cases done.',
		)
		expect(running.find('[data-testid="bulk-job-cancel"]').exists()).toBe(true)
		expect(running.find('[data-testid="bulk-job-commit"]').exists()).toBe(false)
		expect(
			running.get('[data-testid="bulk-job-download"]').attributes('href'),
		).toBe('/download/7')

		const finished = await mountPanel(FINISHED)
		expect(finished.find('[data-testid="bulk-job-cancel"]').exists()).toBe(false)
	})

	it('offers running the rest only after a job stopped short', async () => {
		const cancelled = await mountPanel({
			...FINISHED,
			state: 'cancelled',
			processed: 120,
		})
		expect(cancelled.find('[data-testid="bulk-job-retry"]').exists()).toBe(true)

		const completed = await mountPanel(FINISHED)
		expect(completed.find('[data-testid="bulk-job-retry"]').exists()).toBe(false)
	})

	it('the commit is the only button that writes, and it hands the job up', async () => {
		commitBulkJob.mockResolvedValue({
			...FINISHED,
			state: 'running',
			processed: 0,
		})

		const wrapper = await mountPanel({
			...FINISHED,
			state: 'previewed',
			processed: 0,
		})
		await wrapper.get('[data-testid="bulk-job-commit"]').trigger('click')
		await flushPromises()

		expect(commitBulkJob).toHaveBeenCalledWith(7)
		expect(wrapper.emitted('update:job')[0][0].state).toBe('running')
	})

	it("says so when the act could not be started, in the server's own words", async () => {
		commitBulkJob.mockRejectedValue({
			response: {
				data: { error: 'This act takes at most 500 cases at a time' },
			},
		})

		const wrapper = await mountPanel({
			...FINISHED,
			state: 'previewed',
			processed: 0,
		})
		await wrapper.get('[data-testid="bulk-job-commit"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain(
			'This act takes at most 500 cases at a time',
		)
	})

	it('a stopped job tells a handler how far it got before it stopped', async () => {
		const wrapper = await mountPanel({
			...FINISHED,
			state: 'cancelled',
			processed: 120,
		})

		expect(wrapper.get('[data-testid="bulk-job-state"]').text()).toBe(
			'Stopped. 120 of 400 cases were done first.',
		)
		expect(
			wrapper.get('[data-testid="bulk-job-bar"]').attributes('data-value'),
		).toBe('30')
	})

	it('emits finished once the job has stopped, so the list re-reads', async () => {
		const wrapper = await mountPanel(FINISHED)

		expect(wrapper.emitted('finished')).toHaveLength(1)
	})
})
