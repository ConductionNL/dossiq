// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The two sentences a handler reads about a case that is waiting on somebody.
 *
 * 🔴 NEITHER OF THEM COMPUTES A DAY. The count arrives from the server, beside
 * every other number in the queue, and what these choose is the words. A count
 * computed a second time in the browser would drift the first time the server's
 * one was fixed, and drift quietly.
 *
 * WHAT IS PINNED: that a case nobody is waiting on renders NOTHING rather than
 * "waiting on us for 0 days", because a line under every case in the queue that
 * says nothing is a line people stop reading; that one reminder reads "chased
 * once" rather than "chased 1 times"; and that a party the server learns before
 * the bundle does is passed through rather than dropped.
 *
 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
 */

import { describe, expect, it } from 'vitest'
import { pauseSentence, termRows } from '../../src/utils/caseTerms.js'
import { waitingParty, waitingSentence } from '../../src/utils/personalQueueHelpers.js'

/**
 * A `t` that fills the placeholders, so the assertions read like the screen.
 *
 * @param {string} app  The app id, ignored here.
 * @param {string} text The source string.
 * @param {object} vars The placeholder values.
 * @return {string} The filled string.
 */
function t(app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match))
}

describe('the waiting sentence in the personal queue', () => {
	it('names the party, the days and the reminders', () => {
		const item = { waiting: { on: 'applicant', days: 9, chases: 2 } }

		expect(waitingSentence(item, t)).toBe('Waiting on the applicant for 9 days, chased 2 times')
	})

	it('says chased once rather than chased 1 times', () => {
		const item = { waiting: { on: 'thirdParty', days: 12, chases: 1 } }

		expect(waitingSentence(item, t)).toBe('Waiting on a third party for 12 days, chased once')
	})

	it('leaves the reminders out until one has gone', () => {
		const item = { waiting: { on: 'applicant', days: 3, chases: 0 } }

		expect(waitingSentence(item, t)).toBe('Waiting on the applicant for 3 days')
	})

	it('renders nothing for a case nobody is waiting on', () => {
		expect(waitingSentence({}, t)).toBe('')
		expect(waitingSentence({ waiting: {} }, t)).toBe('')
		expect(waitingSentence(null, t)).toBe('')
	})

	it('passes a party it does not know through rather than dropping it', () => {
		expect(waitingParty('another-authority', t)).toBe('another-authority')
	})
})

describe('the pause sentence on the case terms panel', () => {
	it('names the reason and how many reminders went out', () => {
		const term = { status: 'paused', pauseReason: 'Aanvulling gevraagd', chasesSent: 2 }

		expect(pauseSentence(term, t)).toBe('Suspended: Aanvulling gevraagd. 2 reminders sent.')
	})

	it('says one reminder rather than 1 reminders', () => {
		const term = { status: 'paused', pauseReason: 'Aanvulling gevraagd', chasesSent: 1 }

		expect(pauseSentence(term, t)).toBe('Suspended: Aanvulling gevraagd. One reminder sent.')
	})

	it('still says the term is suspended when no reason was declared', () => {
		const term = { status: 'paused', pauseReason: '', chasesSent: 0 }

		expect(pauseSentence(term, t)).toBe('Suspended')
	})

	it('says nothing at all about a clock that is running', () => {
		expect(pauseSentence({ status: 'lopend', pauseReason: 'x', chasesSent: 2 }, t)).toBe('')
	})

	it('reaches the rows the panel renders', () => {
		const rows = termRows(
			[{ kind: 'statutory', status: 'paused', pauseReason: 'Advies gevraagd', chasesSent: 0 }],
			t,
		)

		expect(rows[0].pause).toBe('Suspended: Advies gevraagd. No reminder sent yet.')
	})
})
