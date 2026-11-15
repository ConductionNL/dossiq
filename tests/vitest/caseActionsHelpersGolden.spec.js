/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `caseActionRefusal` and `isPlanComplete`
 * (src/utils/caseActionsHelpers.js): every refusal code, and every way a
 * follow-up plan is complete or not.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import { describe, expect, it } from 'vitest'
import { caseActionRefusal, isPlanComplete } from '../../src/utils/caseActionsHelpers.js'

/**
 * A translate function that marks what it translated.
 *
 * @param {string} s The key.
 * @return {string} The marked key.
 */
function marked(s) {
	return '[' + s + ']'
}

describe('caseActionRefusal codes', () => {
	it.each([
		['case_not_found', 'This case could not be read.'],
		['storage_unavailable', 'The case store is not available right now.'],
		['flows_unavailable', 'The case store is not available right now.'],
		['invalid_date', 'Pick a date for the follow-up.'],
		['copy_failed', 'The copy could not be created.'],
		['plan_failed', 'The follow-up could not be planned.'],
		['invalid_recurrence', 'Pick how often the follow-up comes back.'],
		['invalid_end', 'Give the series an end date or a number, not both.'],
		['stop_failed', 'The series could not be stopped.'],
		['unknown', 'This did not work. Try again.'],
		['toString', 'This did not work. Try again.'],
	])('translates %s', (code, sentence) => {
		expect(caseActionRefusal({ code }, marked)).toBe('[' + sentence + ']')
	})

	it('answers the generic line without a body', () => {
		expect(caseActionRefusal(null, marked)).toBe('[This did not work. Try again.]')
		expect(caseActionRefusal({ code: { toString: () => 'copy_failed' } }, marked)).toBe('[The copy could not be created.]')
	})
})

describe('isPlanComplete cases', () => {
	const BASE = { caseType: 'ct', date: '2026-10-01', title: 'Controle' }

	it.each([
		[null, false],
		[{ ...BASE, caseType: ' ' }, false],
		[{ ...BASE, date: '' }, false],
		[{ ...BASE, title: '  ' }, false],
		[{ ...BASE, date: '2026-09-01' }, false],
		[BASE, true],
		[{ ...BASE, recurrence: 'none', end: 'until' }, true],
		[{ ...BASE, date: ' 2026-10-01 ' }, true],
		[{ ...BASE, recurrence: 'weekly' }, true],
		[{ ...BASE, recurrence: 'weekly', end: 'open' }, true],
		[{ ...BASE, recurrence: 'weekly', end: 'other' }, true],
		[{ ...BASE, recurrence: 'weekly', end: 'until', until: '2026-10-01' }, true],
		[{ ...BASE, recurrence: 'weekly', end: 'until', until: ' 2026-12-01 ' }, true],
		[{ ...BASE, recurrence: 'weekly', end: 'until', until: '2026-09-30' }, false],
		[{ ...BASE, recurrence: 'weekly', end: 'until' }, false],
		[{ ...BASE, recurrence: 'weekly', end: 'count', count: 1 }, true],
		[{ ...BASE, recurrence: 'weekly', end: 'count', count: '2' }, true],
		[{ ...BASE, recurrence: 'weekly', end: 'count', count: 0 }, false],
		[{ ...BASE, recurrence: 'weekly', end: 'count' }, false],
		[{ ...BASE, recurrence: 'weekly', end: 'count', count: 'x' }, false],
	])('answers %j → %s', (plan, complete) => {
		expect(isPlanComplete(plan, '2026-09-15')).toBe(complete)
	})

	it('reads a missing earliest date as no lower bound', () => {
		expect(isPlanComplete(BASE, undefined)).toBe(true)
		expect(isPlanComplete(BASE, null)).toBe(true)
	})
})
