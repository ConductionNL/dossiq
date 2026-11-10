/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `refusalMessage` (src/utils/caseLifecycleHelpers.js):
 * every refusal code's sentence goes through t(), the order in which the
 * state-field refusal, failed guards, the code and the fallback are read.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

import { describe, expect, it } from 'vitest'
import { refusalMessage } from '../../src/utils/caseLifecycleHelpers.js'

const CODE = 'state-field-required'

function marked(s) {
	return '[' + s + ']'
}

describe('refusalMessage codes', () => {
	it.each([
		['result_type_required', 'Pick a result before closing this case.'],
		['suspension_not_allowed', 'This case type does not allow suspension.'],
		['extension_not_allowed', 'This case type does not allow an extension.'],
		['extension_limit_reached', 'The term of this case was already extended as often as its case type allows.'],
		['extension_period_not_configured', 'This case type states no extension period.'],
		['extension_period_unreadable', 'This case type states no extension period.'],
		['already_suspended', 'This case is already suspended.'],
		['not_suspended', 'This case is not suspended.'],
		['case_not_closed', 'Only a closed case can be reopened.'],
		['initial_status_not_configured', 'This case type has no first status to reopen into.'],
		['initial_status_not_of_case_type', 'This case type has no first status to reopen into.'],
		['case_not_found', 'This case could not be found.'],
		['reason_required', 'Give a reason first.'],
		['pause_reason_not_declared', 'This case type has no such pause reason. Pick one of its reasons.'],
		['change_failed', 'The case could not be changed.'],
		['approval_outstanding', 'This act waits for an approval that is still open.'],
		['approval_rejected', 'The approval for this act was refused.'],
		['approval_unreadable', 'We could not reach the approval service, so this act stays closed.'],
	])('translates %s', (code, sentence) => {
		expect(refusalMessage({ code, message: 'ignored', error: 'ignored' }, marked)).toBe('[' + sentence + ']')
	})

	it('reads a non-string code as its string form', () => {
		expect(refusalMessage({ code: { toString: () => 'reason_required' } }, marked)).toBe('[Give a reason first.]')
	})

	it('does not match a code by prototype key', () => {
		expect(refusalMessage({ code: 'toString' }, marked)).toBe('[The case could not be changed.]')
		expect(refusalMessage({ code: 'constructor', message: 'Own sentence' }, marked)).toBe('Own sentence')
	})

	it('falls back to message, then error, both untranslated, then the translated default', () => {
		expect(refusalMessage({ code: 'unknown', message: 'M', error: 'E' }, marked)).toBe('M')
		expect(refusalMessage({ code: 'unknown', message: null, error: 'E' }, marked)).toBe('E')
		expect(refusalMessage({ error: 42 }, marked)).toBe('42')
		expect(refusalMessage(null, marked)).toBe('[The case could not be changed.]')
		expect(refusalMessage(undefined, 'not a function')).toBe('The case could not be changed.')
	})

	it('joins failed guard names before reading the code', () => {
		const body = {
			code: 'reason_required',
			failedGuards: [{ failureMessage: 'A' }, { message: 'B' }, { type: 'c' }, null, { type: '' }],
		}
		expect(refusalMessage(body, marked)).toBe('A B c')
	})

	it('reads the code when no failed guard carries a name', () => {
		expect(refusalMessage({ code: 'reason_required', failedGuards: [null, {}] }, marked)).toBe('[Give a reason first.]')
		expect(refusalMessage({ code: 'reason_required', failedGuards: 'x' }, marked)).toBe('[Give a reason first.]')
	})

	it('reads a state-field refusal before guards and code, untranslated', () => {
		const body = {
			failedGuards: [{ failureMessage: 'A' }],
			errors: { code: CODE, message: 'Fill in the field.' },
		}
		expect(refusalMessage(body, marked)).toBe('Fill in the field.')
	})

	it('passes over a state-field refusal without a message', () => {
		const body = {
			failedGuards: [{ failureMessage: 'A' }],
			errors: { code: CODE, message: '' },
		}
		expect(refusalMessage(body, marked)).toBe('A')
	})
})
