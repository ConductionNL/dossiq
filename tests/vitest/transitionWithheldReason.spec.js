// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the case page says about a move it will not offer.
 *
 * WITHHOLDING BEATS REFUSING, and only if the reason arrives with it. A
 * transition that is simply absent teaches the handler that the list is
 * unreliable; one that is absent with "waiting on the advice request" in its
 * place teaches them what to fetch. The difference between the two is entirely
 * on this strip, so these are the assertions that decide whether the change is
 * worth anything to a person.
 *
 * The one that earns its place hardest: an entry with NO reason is dropped. A
 * line saying a move is unavailable and refusing to say why is worse than no
 * line at all, and it is exactly what a half-wired backend would send.
 *
 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CaseStatusDeclarationPanel from '../../src/components/case/CaseStatusDeclarationPanel.vue'
import {
	withheldSentence,
	withheldTransitions,
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

/** A quiet current-status block, so only the withheld half is under test. */
const QUIET = { waitingOn: 'us', dwell: { days: 0, breached: false } }

describe('the withheld shapes', () => {
	it('drops an entry that names no reason', () => {
		expect(
			withheldTransitions([
				{ id: 't1', label: 'Close', reasons: [] },
				{ id: 't2', label: 'Withdraw', reasons: ['  ', ''] },
			]),
		).toEqual([])
	})

	it('keeps an entry that says what is in the way', () => {
		expect(
			withheldTransitions([
				{ id: 't1', label: 'Close', reasons: ['the advice request'] },
			]),
		).toEqual([{ id: 't1', label: 'Close', reasons: ['the advice request'] }])
	})

	it('answers an empty list for a body that carries none', () => {
		expect(withheldTransitions(undefined)).toEqual([])
		expect(withheldTransitions(null)).toEqual([])
	})

	it('names the move and the reason in one sentence', () => {
		const sentence = withheldSentence({
			label: 'Close the case',
			reasons: ['the advice request'],
		})
		expect(sentence).toContain('Close the case')
		expect(sentence).toContain('the advice request')
	})

	it('still says what is in the way when the move has no label', () => {
		expect(withheldSentence({ label: '', reasons: ['the advice request'] })).toContain(
			'the advice request',
		)
	})

	it('reads the FIRST reason, because a handler acts on one thing at a time', () => {
		expect(
			withheldSentence({ label: 'Close', reasons: ['the advice request', 'the fee'] }),
		).toContain('the advice request')
	})
})

describe('the withheld moves on the case page', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('renders one line per withheld move, with its reason', async () => {
		const wrapper = await mountWith({
			current: QUIET,
			derivation: null,
			withheld: [
				{ id: 't1', label: 'Close the case', toStatus: 'done', reasons: ['the advice request'] },
				{ id: 't2', label: 'Withdraw', toStatus: 'withdrawn', reasons: ['the outstanding fee'] },
			],
		})

		const entries = wrapper.findAll('[data-testid="case-status-withheld-entry"]')
		expect(entries).toHaveLength(2)
		expect(entries[0].text()).toContain('the advice request')
		expect(entries[1].text()).toContain('the outstanding fee')
	})

	it('says nothing at all when no move is withheld', async () => {
		const wrapper = await mountWith({
			current: QUIET,
			derivation: null,
			withheld: [],
		})

		expect(wrapper.find('[data-testid="case-status-withheld"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="case-status-declaration"]').exists()).toBe(false)
	})

	it('renders the explanation an administrator wrote on the status', async () => {
		const wrapper = await mountWith({
			current: { ...QUIET, statusDescription: 'The file is with the advisory body.' },
			derivation: null,
			withheld: [],
		})

		expect(wrapper.find('[data-testid="case-status-explanation"]').text()).toBe(
			'The file is with the advisory body.',
		)
	})

	it('renders nothing for a status whose explanation is empty', async () => {
		const wrapper = await mountWith({
			current: { ...QUIET, statusDescription: '   ' },
			derivation: null,
			withheld: [],
		})

		expect(wrapper.find('[data-testid="case-status-explanation"]').exists()).toBe(false)
	})
})
