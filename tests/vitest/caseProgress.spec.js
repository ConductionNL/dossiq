// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Progress and days left are the SERVER'S numbers, rendered and not derived.
 *
 * A stored percentage is wrong the moment a term moves, and terms move on every
 * pause, extension and calendar change. The same is true of a percentage the
 * browser computes for itself: it is a second calculator of the same question,
 * and the second one eventually differs from the first on a case that was
 * paused over a weekend.
 *
 * So these cases hold the panel to rendering what came back. The mutation they
 * would catch is the obvious-looking one: somebody adding
 * `phasesDone / phasesTotal` in a computed property, which agrees with the
 * server on a tidy fixture and disagrees the moment a term is extended.
 *
 * The extension case is the one that proves it: the same case, read twice, with
 * the server answering a different number the second time and no write in
 * between.
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-reporting/spec.md
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { daysLeftSentence, needsAttention } from '../../src/utils/caseTerms.js'

vi.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: defineComponent({
		name: 'NcLoadingIcon',
		props: ['size'],
		render() {
			return h('span', { class: 'nc-loading-icon-stub' })
		},
	}),
}))

const { default: CaseTermsTab } =
	await import('../../src/views/cases/components/CaseTermsTab.vue')
const { fetchCaseTerms } = await import('../../src/services/caseTermsApi.js')

/** The translate stub, English source plus {placeholder} substitution. */
function translate(app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (match, key) =>
		key in vars ? String(vars[key]) : match,
	)
}

/** A case two phases into four with half its term consumed. */
function halfway(progressOverrides = {}, daysLeft = 28) {
	return {
		case: 'c1',
		terms: [
			{
				id: 's1',
				kind: 'statutory',
				endDate: '2026-10-13',
				daysLeft,
				overdue: false,
				citizenVisible: true,
			},
		],
		progress: {
			progress: 50,
			daysLeft,
			phasesDone: 2,
			phasesTotal: 4,
			termConsumed: 50,
			phaseOverdue: false,
			plannedOverdue: false,
			statutoryOverdue: false,
			...progressOverrides,
		},
	}
}

describe('A handler triages by progress', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('shows the progress figure and the days-left count the server sent', async () => {
		axios.get.mockResolvedValue({ data: halfway() })

		const wrapper = mount(CaseTermsTab, { props: { objectId: 'c1' } })
		await flushPromises()

		expect(wrapper.find('.case-terms-tab__progress-figure').text()).toBe('50%')
		expect(
			wrapper.find('.case-terms-tab__progress-bar').attributes('value'),
		).toBe('50')
		expect(wrapper.text()).toContain('2 of 4 phases done')
		expect(wrapper.text()).toContain('28 days left')
	})

	it('renders the server figure even when the phase counts disagree with it', async () => {
		// Two of four phases is fifty per cent by the obvious arithmetic, and the
		// server says seventy because half the term is gone as well. A panel that
		// computed its own number would print 50 here.
		axios.get.mockResolvedValue({ data: halfway({ progress: 70 }) })

		const wrapper = mount(CaseTermsTab, { props: { objectId: 'c1' } })
		await flushPromises()

		expect(wrapper.find('.case-terms-tab__progress-figure').text()).toBe('70%')
	})

	it('shows 18 days left after an extension of 14, with no write in between', async () => {
		axios.get.mockResolvedValueOnce({ data: halfway({}, 4) })
		const before = mount(CaseTermsTab, { props: { objectId: 'c1' } })
		await flushPromises()
		expect(before.text()).toContain('4 days left')

		axios.get.mockResolvedValueOnce({ data: halfway({}, 18) })
		const after = mount(CaseTermsTab, { props: { objectId: 'c1' } })
		await flushPromises()
		expect(after.text()).toContain('18 days left')

		// Two reads, no writes: nothing here stores a progress value.
		expect(axios.post).not.toHaveBeenCalled()
		expect(axios.put).not.toHaveBeenCalled()
		expect(axios.patch).not.toHaveBeenCalled()
	})

	it('asks dossiq for the numbers rather than computing them anywhere', async () => {
		axios.get.mockResolvedValue({ data: halfway() })

		await fetchCaseTerms('c1')

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get.mock.calls[0][0]).toContain(
			'/apps/dossiq/api/cases/c1/terms',
		)
	})

	it('says a case type declaring no term has no clock, and does not call it unreadable', async () => {
		axios.get.mockResolvedValue({
			data: { case: 'c1', terms: [], progress: {} },
		})

		const wrapper = mount(CaseTermsTab, { props: { objectId: 'c1' } })
		await flushPromises()

		expect(wrapper.find('.case-terms-tab__empty').exists()).toBe(true)
		expect(wrapper.find('.case-terms-tab__unreadable').exists()).toBe(false)
	})

	it('reads a due-today clock as due today rather than as zero days left', () => {
		expect(
			daysLeftSentence({ endDate: '2026-09-15', daysLeft: 0 }, translate),
		).toBe('Due today')
		expect(
			daysLeftSentence(
				{ endDate: '2026-09-15', daysLeft: -3, overdue: true },
				translate,
			),
		).toBe('3 days over')
		expect(daysLeftSentence({ endDate: '', daysLeft: 0 }, translate)).toBe(
			'No end date',
		)
	})

	it('flags attention on any overrun, and on none when there is none', () => {
		expect(
			needsAttention({
				statutoryOverdue: false,
				plannedOverdue: false,
				phaseOverdue: false,
			}),
		).toBe(false)
		expect(
			needsAttention({
				statutoryOverdue: false,
				plannedOverdue: true,
				phaseOverdue: false,
			}),
		).toBe(true)
		expect(
			needsAttention({
				statutoryOverdue: false,
				plannedOverdue: false,
				phaseOverdue: true,
			}),
		).toBe(true)
		expect(needsAttention(undefined)).toBe(false)
	})
})
