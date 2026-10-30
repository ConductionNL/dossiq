/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every shown AI suggestion ends in exactly one oversight report: the person's
 * decision, or ignored with why (superseded, closed, expired).
 *
 * @spec openspec/specs/ai-oversight-dispatch/spec.md
 */
import { describe, expect, it, vi } from 'vitest'
import { createSuggestionTracker } from '../../src/services/aiSuggestionTracker.js'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token' }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

/**
 * A tracker with a recording report and manual timers.
 *
 * @return {object} `{tracker, reports, timers}`.
 */
function setup() {
	const reports = []
	const timers = []
	const tracker = createSuggestionTracker({
		caseId: 'c-1',
		report: (body, options) => {
			reports.push({ body, options })
		},
		setTimer: (fn) => {
			timers.push(fn)
			return timers.length
		},
		clearTimer: () => {},
	})
	return { tracker, reports, timers }
}

const ANSWER = { type: 'qa', text: 'Binnen de termijn.', prompt: 'Termijn?' }

describe('createSuggestionTracker', () => {
	it('reports a decision once, with the suggestion and its id', async () => {
		const { tracker, reports } = setup()
		const id = tracker.show('question', ANSWER)

		await tracker.decide('question', 'accepted')
		await tracker.decide('question', 'rejected')

		expect(reports).toHaveLength(1)
		expect(reports[0].body).toMatchObject({
			caseId: 'c-1',
			type: 'qa',
			userAction: 'accepted',
			suggestion: 'Binnen de termijn.',
			prompt: 'Termijn?',
			suggestionId: id,
		})
		expect(tracker.pending('question')).toBe(false)
	})

	it('reports an adapted suggestion with what was used', async () => {
		const { tracker, reports } = setup()
		tracker.show('question', ANSWER)

		await tracker.decide('question', 'modified', {
			actualValue: 'Net binnen de termijn.',
		})

		expect(reports[0].body.userAction).toBe('modified')
		expect(reports[0].body.actualValue).toBe('Net binnen de termijn.')
	})

	it('reports an undecided suggestion superseded by the next one', () => {
		const { tracker, reports } = setup()
		tracker.show('question', ANSWER)
		tracker.show('question', { ...ANSWER, text: 'Buiten de termijn.' })

		expect(reports).toHaveLength(1)
		expect(reports[0].body).toMatchObject({
			userAction: 'ignored',
			unusedBecause: 'superseded',
			suggestion: 'Binnen de termijn.',
		})
		expect(tracker.pending('question')).toBe(true)
	})

	it('leaves other slots alone when one is superseded', () => {
		const { tracker, reports } = setup()
		tracker.show('question', ANSWER)
		tracker.show('feature:summary', {
			type: 'decision_support',
			text: 'Verleend.',
		})

		expect(reports).toHaveLength(0)
	})

	it('reports an undecided suggestion expired when its lifetime ends', () => {
		const { tracker, reports, timers } = setup()
		tracker.show('question', ANSWER)

		timers[0]()

		expect(reports[0].body).toMatchObject({
			userAction: 'ignored',
			unusedBecause: 'expired',
		})
	})

	it('reports nothing when a decided suggestion expires', async () => {
		const { tracker, reports, timers } = setup()
		tracker.show('question', ANSWER)
		await tracker.decide('question', 'rejected')

		timers[0]()

		expect(reports).toHaveLength(1)
	})

	it('reports every undecided suggestion closed, keeping the page alive when leaving', async () => {
		const { tracker, reports } = setup()
		tracker.show('question', ANSWER)
		tracker.show('feature:summary', {
			type: 'decision_support',
			text: 'Verleend.',
		})

		await tracker.closeAll({ leaving: true })

		expect(reports).toHaveLength(2)
		expect(reports.every((r) => r.body.unusedBecause === 'closed')).toBe(true)
		expect(reports[0].options).toEqual({ leaving: true })
	})

	it('does not fail when the report cannot be sent', async () => {
		const tracker = createSuggestionTracker({
			caseId: 'c-1',
			report: () => Promise.reject(new Error('offline')),
			setTimer: () => 1,
			clearTimer: () => {},
		})
		tracker.show('question', ANSWER)

		await expect(tracker.decide('question', 'accepted')).resolves.toBeUndefined()
	})
})
