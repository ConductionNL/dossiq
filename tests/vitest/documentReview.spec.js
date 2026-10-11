// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Document review on the case (board DqWooReview), and the pages-seen reporter
 * a review viewer calls as pages come into view.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPagesSeenReporter, pagesSeenUrl } from '../../src/services/pagesSeenReporter.js'

const { default: DocumentReviewPanel } =
	await import('../../src/components/case/DocumentReviewPanel.vue')

describe('pages-seen reporter', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		axios.post.mockReset()
		axios.post.mockResolvedValue({ data: { unseen: [] } })
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('testEachDisplayedPageIsReportedOnce', async () => {
		const saved = vi.fn()
		const reporter = createPagesSeenReporter({ caseId: 'case-x', documentRef: 'doc-1', onSaved: saved })

		for (const page of [1, 2, 3, 2, 1, 3]) {
			reporter.displayed(page, 3)
		}
		await vi.runAllTimersAsync()
		for (const page of [1, 2, 3]) {
			reporter.displayed(page, 3)
		}
		await vi.runAllTimersAsync()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith(pagesSeenUrl('case-x', 'doc-1'), { pages: [1, 2, 3], pageCount: 3 })
		expect(saved).toHaveBeenCalledWith({ unseen: [] })
		expect(reporter.reported()).toEqual([1, 2, 3])
	})

	it('reports a page again after a failed post, and ignores what is not a page', async () => {
		axios.post.mockRejectedValueOnce(new Error('offline'))
		const failed = vi.fn()
		const reporter = createPagesSeenReporter({ caseId: 'case-x', documentRef: 'doc-1', onError: failed })

		reporter.displayed(0)
		reporter.displayed('x')
		reporter.displayed(4)
		await reporter.flush()
		expect(failed).toHaveBeenCalled()
		expect(reporter.reported()).toEqual([])

		reporter.displayed(4)
		await reporter.flush()
		expect(axios.post).toHaveBeenCalledTimes(2)
		expect(axios.post.mock.calls[1][1]).toEqual({ pages: [4] })
	})
})

describe('DocumentReviewPanel', () => {
	/**
	 * Answer the panel's reads.
	 *
	 * @param {object} summary The summary.
	 * @param {object} recall  The recall answer.
	 * @return {void}
	 */
	function answer(summary, recall = { rule: null, reviewStarted: false, samples: [] }) {
		axios.get.mockImplementation((url) => {
			if (url.endsWith('/review/summary')) {
				return Promise.resolve({ data: summary })
			}
			if (url.endsWith('/review/batches')) {
				return Promise.resolve({ data: { results: [{ id: 'b-1', name: 'Mail 2025', assignee: 'anouk', documents: ['d1', 'd2'], progress: { assessed: 1, total: 2 } }] } })
			}
			return Promise.resolve({ data: recall })
		})
	}

	/**
	 * Mount the panel on case X.
	 *
	 * @return {object} The wrapper.
	 */
	function mountPanel() {
		return mount(DocumentReviewPanel, {
			props: { objectId: 'case-x' },
			global: {
				stubs: {
					NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
					NcEmptyContent: true,
					NcLoadingIcon: true,
					AlertCircleOutline: true,
					RecallSampleDialog: true,
					ReviewBatchDialog: true,
				},
			},
		})
	}

	const enabledSummary = {
		enabled: true,
		documents: 10,
		relevance: { inScope: 6, outOfScope: 3, unmarked: 1 },
		verdicts: { total: 4 },
		outstanding: { count: 3, documents: ['d4', 'd5', 'd9'] },
		unseen: { d4: [7], d5: [2, 3] },
	}

	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('says so in one sentence when the case type does not review its documents', async () => {
		answer({ enabled: false })
		const wrapper = mountPanel()
		await flushPromises()

		expect(wrapper.find('[data-testid="document-review-off"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="document-review-status"]').exists()).toBe(false)
		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	it('shows the counts, the unseen pages, the batches and what is open', async () => {
		answer(enabledSummary)
		const wrapper = mountPanel()
		await flushPromises()

		expect(wrapper.find('[data-testid="document-review-tile-in-scope"]').text()).toContain('6')
		expect(wrapper.find('[data-testid="document-review-tile-out-of-scope"]').text()).toContain('3')
		expect(wrapper.find('[data-testid="document-review-tile-unmarked"]').text()).toContain('1')
		expect(wrapper.find('[data-testid="document-review-unseen"]').text()).toContain('3')
		expect(wrapper.findAll('[data-testid="document-review-batch"]')).toHaveLength(1)
		expect(wrapper.findAll('[data-testid="document-review-open-document"]')).toHaveLength(3)
		expect(wrapper.find('[data-testid="document-review-rule-form"]').exists()).toBe(true)
	})

	it('declares the stopping rule as a share, and shows a met sample and an open one', async () => {
		answer(enabledSummary)
		axios.post.mockResolvedValue({ data: { targetRecall: 0.8, confidence: 0.95, declaredBy: 'handler', declaredAt: '2026-10-06T10:00:00Z' } })
		const wrapper = mountPanel()
		await flushPromises()

		await wrapper.find('[data-testid="document-review-rule-form"]').trigger('submit')
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/dossiq/api/cases/case-x/review/stopping-rule',
			{ targetRecall: 0.8, confidence: 0.95 },
		)
		expect(wrapper.find('[data-testid="document-review-rule"]').exists()).toBe(true)

		answer(enabledSummary, {
			rule: { targetRecall: 0.8, confidence: 0.95 },
			reviewStarted: true,
			samples: [
				{ id: 's-2', drawnAt: '2026-10-08T10:00:00Z', documents: new Array(200).fill('d'), nullSetSize: 1600, found: 400, k: 2, confidence: 0.95, recallEstimate: 0.9615, recallLowerBound: 0.8892, stoppingRuleMetAt: '2026-10-09T12:05:00Z', progress: { judged: 200, total: 200 } },
				{ id: 's-3', drawnAt: '2026-10-09T10:00:00Z', documents: new Array(200).fill('d'), nullSetSize: 1600, found: 400, progress: { judged: 150, total: 200 } },
			],
		})
		await wrapper.vm.load()
		await flushPromises()
		const states = wrapper.findAll('[data-testid="document-review-sample-state"]').map((node) => node.text())
		expect(states).toEqual(['Stopping rule met', '150 of 200 judged'])
		expect(wrapper.findAll('[data-testid="document-review-sample"]')[0].text()).toContain('88,9')

		axios.post.mockResolvedValue({ data: { id: 's-3', drawnAt: '2026-10-09T10:00:00Z', documents: ['d1'], judgements: { d1: 'in-scope' }, progress: { judged: 1, total: 1 }, recallLowerBound: 0.9, recallEstimate: 0.95, confidence: 0.95 } })
		wrapper.vm.samples = [{ id: 's-3', drawnAt: '2026-10-09T10:00:00Z', documents: ['d1'], judgements: {}, progress: { judged: 0, total: 1 } }]
		await flushPromises()
		await wrapper.find('[data-testid="document-review-judge-in"]').trigger('click')
		await flushPromises()
		expect(axios.post).toHaveBeenLastCalledWith(
			'/index.php/apps/dossiq/api/cases/case-x/review/recall/samples/s-3/judgements',
			{ documentRef: 'd1', inScope: true },
		)
		expect(wrapper.findAll('[data-testid="document-review-sample-document"]')).toHaveLength(0)
	})
})
