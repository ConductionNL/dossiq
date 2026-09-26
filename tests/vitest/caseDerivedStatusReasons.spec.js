// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the case page says about a derived status that has not fired.
 *
 * This is the half of the feature nobody can see by looking at the case. A
 * derived status is not a move a handler can pick, so when its conditions are
 * unmet there is no button to press and no refusal to read: the case simply
 * sits where it is. If the reasons are dark, "Complete never arrives" is
 * indistinguishable from "this case type derives nothing", and both look like
 * the product working.
 *
 * The assertions that earn their place:
 *
 *  - the strip reads the TRANSITION endpoint, because that is where the engine
 *    publishes the verdict. A second endpoint would be a second round trip and
 *    a second place for the verdict to be computed;
 *  - the missing things are named ONE PER LINE, in the author's own words. A
 *    count ("3 items missing") sends the handler back to the case type;
 *  - the strip is SILENT on a case with nothing to say, rather than rendering
 *    three empty lines, and silent rather than erroring on an instance whose
 *    transition engine cannot answer at all;
 *  - a derivation with an empty `unmet` list is nothing to say. The engine
 *    answers `null` there, but a shape that arrived with an empty array would
 *    otherwise render a heading over no reasons.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CaseStatusDeclarationPanel from '../../src/components/case/CaseStatusDeclarationPanel.vue'
import {
	derivationHeading,
	derivationReasons,
	dwellLabel,
	isDwellBreached,
	waitingOnLabel,
} from '../../src/utils/statusDeclaration.js'

/**
 * Mount the strip over one `/available-transitions` answer.
 *
 * @param {object} answer The engine's answer body.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountWith(answer) {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: answer })
	const wrapper = mount(CaseStatusDeclarationPanel, {
		props: { objectId: 'case-1' },
	})
	await flushPromises()
	return wrapper
}

describe('the declaration shapes', () => {
	it('says nothing about a case that is ours to move', () => {
		expect(waitingOnLabel('us')).toBe('')
		expect(waitingOnLabel('')).toBe('')
		expect(waitingOnLabel(undefined)).toBe('')
	})

	it('tells the applicant apart from a third party', () => {
		expect(waitingOnLabel('applicant')).toContain('applicant')
		expect(waitingOnLabel('thirdParty')).not.toContain('applicant')
		expect(waitingOnLabel('applicant')).not.toBe(waitingOnLabel('thirdParty'))
	})

	it('says nothing about a case that entered its status today', () => {
		expect(dwellLabel({ days: 0 })).toBe('')
		expect(dwellLabel(undefined)).toBe('')
	})

	it('reads the breach off the engine rather than recomputing the boundary', () => {
		// Exactly the maximum is not a breach, and that decision lives on the
		// server. A client that compared days against maximum would be a second
		// place for it to be decided differently.
		expect(isDwellBreached({ days: 20, maximum: 20, breached: false })).toBe(
			false,
		)
		expect(isDwellBreached({ days: 21, maximum: 20, breached: true })).toBe(true)
	})

	it('treats an empty unmet list as nothing to say', () => {
		expect(derivationReasons({ name: 'Complete', unmet: [] })).toBeNull()
		expect(derivationReasons({ name: 'Complete', unmet: ['  ', ''] })).toBeNull()
		expect(derivationReasons(null)).toBeNull()
	})

	it('names the status in the heading, so three derived statuses stay apart', () => {
		expect(derivationHeading('Complete')).toContain('Complete')
		expect(derivationHeading('Ready to decide')).toContain('Ready to decide')
	})
})

describe('the case strip', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('asks the transition endpoint, where the engine publishes the verdict', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		mount(CaseStatusDeclarationPanel, { props: { objectId: 'case-9' } })
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(1)
		expect(String(get.mock.calls[0][0])).toContain('available-transitions')
		expect(String(get.mock.calls[0][0])).toContain('case-9')
	})

	it('names every missing thing, one per line, in the words the author wrote', async () => {
		const wrapper = await mountWith({
			current: { waitingOn: 'us', dwell: { days: 0, breached: false } },
			derivation: {
				statusId: 'complete',
				name: 'Complete',
				unmet: ['the site drawing', 'the signed consent form'],
			},
		})

		const missing = wrapper.findAll('[data-testid="case-status-missing"]')
		expect(missing).toHaveLength(2)
		expect(missing[0].text()).toBe('the site drawing')
		expect(missing[1].text()).toBe('the signed consent form')
		expect(
			wrapper.find('[data-testid="case-status-derivation"]').text(),
		).toContain('Complete')
	})

	it('says who the case is waiting on and how long it has been here', async () => {
		const wrapper = await mountWith({
			current: {
				waitingOn: 'thirdParty',
				dwell: { days: 25, maximum: 20, breached: true },
			},
			derivation: null,
		})

		expect(wrapper.find('[data-testid="case-status-waiting"]').exists()).toBe(
			true,
		)
		const dwell = wrapper.find('[data-testid="case-status-dwell"]')
		expect(dwell.text()).toContain('25')
		expect(dwell.classes()).toContain('is-breached')
		// WCAG 2.2 SC 1.4.1: the breached state is in words, not only in colour.
		expect(dwell.text()).toContain('longer than this status allows')
	})

	it('renders nothing at all on a case with nothing to say', async () => {
		const wrapper = await mountWith({
			current: {
				waitingOn: 'us',
				dwell: { days: 0, maximum: null, breached: false },
			},
			derivation: null,
		})

		expect(
			wrapper.find('[data-testid="case-status-declaration"]').exists(),
		).toBe(false)
	})

	it('renders nothing rather than erroring when the engine cannot answer', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('no workflow'))
		const wrapper = mount(CaseStatusDeclarationPanel, {
			props: { objectId: 'case-1' },
		})
		await flushPromises()

		expect(
			wrapper.find('[data-testid="case-status-declaration"]').exists(),
		).toBe(false)
	})
})
