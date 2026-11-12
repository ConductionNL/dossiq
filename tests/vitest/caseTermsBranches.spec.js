// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Every branch of the case-terms sentences: the tone, the days-left count,
 * the pause sentence and the move lines.
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-reporting/spec.md
 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
 * @spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	daysLeftSentence,
	moveLines,
	pauseSentence,
	termTone,
} from '../../src/utils/caseTerms.js'

/**
 * A `t(app, text, vars)` that records the call as one readable string.
 *
 * @param {string} app The app id.
 * @param {string} text The source string.
 * @param {object} [vars] The placeholders.
 * @return {string} The call, written out.
 */
const t3 = (app, text, vars) => app + '|' + text + (vars ? '|' + JSON.stringify(vars) : '')

/**
 * A `t(text, vars)` that records the call as one readable string.
 *
 * @param {string} text The source string.
 * @param {object} [vars] The placeholders.
 * @return {string} The call, written out.
 */
const t2 = (text, vars) => text + (vars ? '|' + JSON.stringify(vars) : '')

describe('termTone', () => {
	it.each([
		[null, undefined, 'unknown'],
		[{ daysLeft: 1 }, undefined, 'unknown'],
		[{ endDate: '2026-11-01', overdue: true, daysLeft: 1 }, undefined, 'overdue'],
		[{ endDate: '2026-11-01', daysLeft: 5 }, undefined, 'soon'],
		[{ endDate: '2026-11-01', daysLeft: 6 }, undefined, 'ontime'],
		[{ endDate: '2026-11-01', daysLeft: 6 }, 6, 'soon'],
		[{ endDate: '2026-11-01', daysLeft: '1' }, undefined, 'ontime'],
		[{ endDate: '2026-11-01', overdue: 'yes', daysLeft: 20 }, undefined, 'ontime'],
	])('reads %j within %s as %s', (term, warnWithin, tone) => {
		expect(termTone(term, warnWithin)).toBe(tone)
	})
})

describe('daysLeftSentence', () => {
	it.each([
		[null, 'dossiq|No end date'],
		[{ endDate: '2026-11-01', daysLeft: '3' }, 'dossiq|No end date'],
		[{ daysLeft: 3 }, 'dossiq|No end date'],
		[{ endDate: '2026-11-01', daysLeft: -4, overdue: true }, 'dossiq|{days} days over|{"days":4}'],
		[{ endDate: '2026-11-01', daysLeft: 0 }, 'dossiq|Due today'],
		[{ endDate: '2026-11-01', daysLeft: 0, overdue: true }, 'dossiq|{days} days over|{"days":0}'],
		[{ endDate: '2026-11-01', daysLeft: -2 }, 'dossiq|{days} days left|{"days":-2}'],
	])('words %j', (term, words) => {
		expect(daysLeftSentence(term, t3)).toBe(words)
	})
})

describe('pauseSentence', () => {
	it.each([
		[null, ''],
		[{ status: 'running', pauseReason: 'x' }, ''],
		[{ status: 'paused' }, 'dossiq|Suspended'],
		[{ status: 'paused', chasesSent: 2 }, 'dossiq|Suspended, {chases} reminders sent|{"chases":2}'],
		[{ status: 'paused', chasesSent: 1, pauseReason: '' }, 'dossiq|Suspended, {chases} reminders sent|{"chases":1}'],
		[{ status: 'paused', chasesSent: NaN, pauseReason: 'docs' }, 'dossiq|Suspended: {reason}. No reminder sent yet.|{"reason":"docs"}'],
		[{ status: 'paused', chasesSent: '3', pauseReason: 'docs' }, 'dossiq|Suspended: {reason}. No reminder sent yet.|{"reason":"docs"}'],
		[{ status: 'paused', chasesSent: 1, pauseReason: 'docs' }, 'dossiq|Suspended: {reason}. One reminder sent.|{"reason":"docs"}'],
		[{ status: 'paused', chasesSent: 4, pauseReason: 'docs' }, 'dossiq|Suspended: {reason}. {chases} reminders sent.|{"reason":"docs","chases":4}'],
	])('words %j', (term, words) => {
		expect(pauseSentence(term, t3)).toBe(words)
	})
})

describe('moveLines', () => {
	it('phrases every shape of move, and drops what is not a move', () => {
		expect(moveLines({
			moves: [
				null,
				'x',
				{ from: '2026-10-01T10:00:00Z', to: '2026-10-15T00:00:00Z', reason: '  Extended  ' },
				{ from: '2026-10-01', to: '2026-10-15', reason: '   ' },
				{ from: '2026-10-01', reason: 'Only from' },
				{ to: '2026-10-15' },
				{},
			],
		}, t2)).toEqual([
			'Moved from {from} to {to}: {reason}|{"from":"2026-10-01","to":"2026-10-15","reason":"Extended"}',
			'Moved from {from} to {to}, with no reason recorded|{"from":"2026-10-01","to":"2026-10-15"}',
			'Moved: {reason}|{"reason":"Only from"}',
			'Moved, with no reason recorded',
			'Moved, with no reason recorded',
		])
	})

	it('answers nothing for a term without a list of moves', () => {
		expect(moveLines(null, t2)).toEqual([])
		expect(moveLines({ moves: {} }, t2)).toEqual([])
	})
})
